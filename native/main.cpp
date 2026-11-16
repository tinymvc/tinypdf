#include "process_io.h"
#include "vendor/json.hpp"
#include <hb.h>
#include <hb-ot.h>
#include <hb-subset.h>
#include <hb-cairo.h>
#include <cairo.h>
#include <librsvg/rsvg.h>
#include <ft2build.h>
#include FT_FREETYPE_H
#include FT_TRUETYPE_TABLES_H
#include FT_OUTLINE_H
#include FT_MULTIPLE_MASTERS_H
#include <fribidi.h>
#include <unicode/ubrk.h>
#include <unicode/uchar.h>
#include <unicode/uscript.h>
#include <unicode/utf8.h>
#include <unicode/uversion.h>
#include <algorithm>
#include <cmath>
#include <cstdint>
#include <iostream>
#include <map>
#include <memory>
#include <set>
#include <sstream>
#include <stdexcept>
#include <string>
#include <vector>
#ifdef _WIN32
#include <fcntl.h>
#include <io.h>
#endif

using Json = nlohmann::json;
constexpr uint32_t MAX_MESSAGE = 64 * 1024 * 1024;

static void require(bool ok, const std::string &message) {
    if (!ok) throw std::runtime_error(message);
}

static std::string base64(const unsigned char *data, size_t size) {
    static const char alphabet[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::string out;
    out.reserve((size + 2) / 3 * 4);
    for (size_t i = 0; i < size; i += 3) {
        uint32_t value = uint32_t(data[i]) << 16;
        if (i + 1 < size) value |= uint32_t(data[i + 1]) << 8;
        if (i + 2 < size) value |= data[i + 2];
        out += alphabet[(value >> 18) & 63];
        out += alphabet[(value >> 12) & 63];
        out += i + 1 < size ? alphabet[(value >> 6) & 63] : '=';
        out += i + 2 < size ? alphabet[value & 63] : '=';
    }
    return out;
}

struct Text {
    std::string utf8;
    std::vector<UChar> utf16;
    std::vector<int> bytes{0};
    std::vector<int> characters{0};
    explicit Text(std::string value) : utf8(std::move(value)) {
        require(utf8.size() <= 1024 * 1024, "Paragraph exceeds 1 MiB");
        int index = 0, count = 0;
        while (index < int(utf8.size())) {
            int start = index;
            UChar32 cp;
            U8_NEXT(utf8.data(), index, int(utf8.size()), cp);
            require(cp >= 0, "Malformed UTF-8 at byte " + std::to_string(start));
            if (cp > 0xffff) {
                utf16.push_back(U16_LEAD(cp));
                bytes.push_back(start);
                characters.push_back(count);
                utf16.push_back(U16_TRAIL(cp));
            } else {
                utf16.push_back(UChar(cp));
            }
            bytes.push_back(index);
            characters.push_back(++count);
        }
    }
    int length() const { return int(utf16.size()); }
    std::string slice(int start, int end) const {
        return utf8.substr(bytes.at(start), bytes.at(end) - bytes.at(start));
    }
    UChar32 at(int index) const {
        UChar32 cp;
        U16_NEXT(utf16.data(), index, length(), cp);
        return cp;
    }
};

static std::vector<int> boundaries(const Text &text, UBreakIteratorType type, const std::string &locale) {
    UErrorCode status = U_ZERO_ERROR;
    auto iter = ubrk_open(type, locale.c_str(), text.utf16.data(), text.length(), &status);
    require(U_SUCCESS(status) && iter, "ICU boundary analysis failed");
    std::vector<int> result;
    for (int p = ubrk_first(iter); p != UBRK_DONE; p = ubrk_next(iter)) result.push_back(p);
    ubrk_close(iter);
    return result;
}

#include "bidi.h"

struct Font {
    FT_Face ft = nullptr;
    hb_blob_t *source = nullptr;
    hb_face_t *face = nullptr;
    hb_font_t *font = nullptr;
    std::vector<hb_variation_t> variations;
    std::string name;
    bool truetype = false;
    bool color = false;
    unsigned upem = 1000;
    ~Font() {
        if (font) hb_font_destroy(font);
        if (face) hb_face_destroy(face);
        if (ft) FT_Done_Face(ft);
        if (source) hb_blob_destroy(source);
    }
};

static hb_face_t *loadFontFace(hb_blob_t *source, unsigned index, FT_Face ft) {
    auto face = hb_face_create(source, index);
    if (hb_face_get_glyph_count(face)) return face;
    hb_face_destroy(face);

    // FreeType decodes WOFF/WOFF2. Materialize the decoded SFNT tables so
    // shaping and subsetting share a complete, immutable HarfBuzz face.
    face = hb_face_builder_create();
    FT_ULong count = 0;
    require(!FT_Sfnt_Table_Info(ft, 0, nullptr, &count), "Cannot enumerate font tables");
    for (FT_UInt i = 0; i < count; ++i) {
        FT_ULong tag = 0, length = 0;
        require(!FT_Sfnt_Table_Info(ft, i, &tag, &length), "Cannot inspect font table");
        std::vector<char> bytes(length);
        require(!FT_Load_Sfnt_Table(ft, tag, 0, reinterpret_cast<FT_Byte *>(bytes.data()), &length),
                "Cannot decode font table");
        auto table = hb_blob_create(bytes.data(), length, HB_MEMORY_MODE_DUPLICATE, nullptr, nullptr);
        bool added = hb_face_builder_add_table(face, tag, table);
        hb_blob_destroy(table);
        require(added, "Cannot construct decoded font face");
    }
    return face;
}

struct Span {
    int start = 0, end = 0, item = 0;
    Json style;
};

struct Cluster {
    int start, end, span, font;
    UScriptCode script;
    bool sideways = false;
};

class Engine {
    FT_Library library = nullptr;
    std::map<int, std::unique_ptr<Font>> fonts;
    int nextFont = 1;

    Font &getFont(int id) {
        require(fonts.count(id), "Unknown font instance");
        return *fonts.at(id);
    }

    bool covers(Font &font, const Text &text, int start, int end) {
        for (int p = start; p < end;) {
            UChar32 cp;
            U16_NEXT(text.utf16.data(), p, end, cp);
            if (u_hasBinaryProperty(cp, UCHAR_DEFAULT_IGNORABLE_CODE_POINT) || u_iscntrl(cp)) continue;
            hb_codepoint_t glyph;
            if (!hb_font_get_nominal_glyph(font.font, cp, &glyph) || glyph == 0) return false;
        }
        return true;
    }

    Json registerFont(const Json &request) {
        require(fonts.size() < 1024, "Too many font instances");
        auto font = std::make_unique<Font>();
        std::string path = request.at("path");
        require(path.find('\0') == std::string::npos, "Invalid font path");
        int index = request.value("faceIndex", 0);
        require(index >= 0, "Invalid font face index");
        // HarfBuzz opens UTF-8 paths with the wide Windows API. Share that
        // mapping with FreeType instead of passing UTF-8 to CreateFileA.
        font->source = hb_blob_create_from_file_or_fail(path.c_str());
        require(font->source != nullptr, "Cannot read font: " + path);
        unsigned length = 0;
        auto bytes = hb_blob_get_data(font->source, &length);
        require(!FT_New_Memory_Face(library, reinterpret_cast<const FT_Byte *>(bytes), length, index, &font->ft),
                "Cannot load font: " + path);
        require(index < font->ft->num_faces && FT_IS_SFNT(font->ft), "Unsupported font face");
        font->face = loadFontFace(font->source, index, font->ft);
        font->upem = hb_face_get_upem(font->face);
        require(hb_face_get_glyph_count(font->face) > 0, "Font has no glyphs");
        auto os2 = static_cast<TT_OS2 *>(FT_Get_Sfnt_Table(font->ft, ft_sfnt_os2));
        require(!os2 || !(os2->fsType & (0x0002 | 0x0100 | 0x0200)), "Font license bits prohibit outline subsetting/embedding");
        Json axes = Json::object();
        unsigned axisCount = hb_ot_var_get_axis_count(font->face);
        std::vector<hb_ot_var_axis_info_t> axisInfo(axisCount);
        hb_ot_var_get_axis_infos(font->face, 0, &axisCount, axisInfo.data());
        Json requested = request.value("variations", Json::object());
        for (const auto &axis : axisInfo) {
            char tag[5] = {};
            hb_tag_to_string(axis.tag, tag);
            double value = requested.value(std::string(tag), double(axis.default_value));
            require(std::isfinite(value), "Invalid variation coordinate");
            value = std::clamp(value, double(axis.min_value), double(axis.max_value));
            font->variations.push_back({axis.tag, float(value)});
            axes[tag] = {axis.min_value, axis.default_value, axis.max_value, value};
        }
        font->font = hb_font_create(font->face);
        hb_ot_font_set_funcs(font->font);
        hb_font_set_scale(font->font, font->upem, font->upem);
        hb_font_set_variations(font->font, font->variations.data(), font->variations.size());
        if (!font->variations.empty()) {
            std::vector<FT_Fixed> coords;
            for (const auto &v : font->variations) coords.push_back(FT_Fixed(std::lround(v.value * 65536)));
            require(!FT_Set_Var_Design_Coordinates(font->ft, coords.size(), coords.data()), "Cannot instantiate font axes");
        }
        unsigned nameLength = hb_ot_name_get_utf8(font->face, HB_OT_NAME_ID_POSTSCRIPT_NAME, HB_LANGUAGE_INVALID, nullptr, nullptr) + 1;
        font->name.resize(nameLength);
        hb_ot_name_get_utf8(font->face, HB_OT_NAME_ID_POSTSCRIPT_NAME, HB_LANGUAGE_INVALID, &nameLength, font->name.data());
        font->name.resize(nameLength);
        if (font->name.empty()) font->name = "TinyPDFFont";
        FT_ULong glyfLength = 0;
        font->truetype = !FT_Load_Sfnt_Table(font->ft, HB_TAG('g','l','y','f'), 0, nullptr, &glyfLength);
        font->color = FT_HAS_COLOR(font->ft);
        hb_font_extents_t extents{};
        hb_font_get_h_extents(font->font, &extents);
        int id = nextFont++;
        double scale = 1000.0 / font->upem;
        Json result = {{"font", id}, {"name", font->name}, {"upem", font->upem}, {"axes", axes},
            {"color", font->color}, {"format", font->truetype ? "truetype" : "cff"},
            {"ascent", extents.ascender * scale}, {"descent", extents.descender * scale},
            {"lineGap", extents.line_gap * scale},
            {"bbox", {font->ft->bbox.xMin * scale, font->ft->bbox.yMin * scale,
                      font->ft->bbox.xMax * scale, font->ft->bbox.yMax * scale}}};
        fonts[id] = std::move(font);
        return result;
    }

    std::vector<Cluster> clusters(const Text &text, const std::vector<Span> &spans,
                                  const std::vector<int> &graphemes, bool vertical) {
        std::vector<Cluster> result;
        int span = 0;
        UScriptCode previous = USCRIPT_COMMON;
        for (size_t i = 1; i < graphemes.size(); ++i) {
            int start = graphemes[i - 1], end = graphemes[i];
            while (span + 1 < int(spans.size()) && start >= spans[span].end) ++span;
            UErrorCode status = U_ZERO_ERROR;
            auto script = uscript_getScript(text.at(start), &status);
            if (script == USCRIPT_INHERITED || script == USCRIPT_COMMON) script = previous;
            else previous = script;
            const auto &style = spans[span].style;
            int chosen = 0;
            if (!style.contains("object")) {
                for (const auto &id : style.at("fonts")) {
                    if (covers(getFont(id), text, start, end)) { chosen = id; break; }
                }
                if (!chosen) {
                    std::ostringstream message;
                    message << "No registered font covers";
                    for (int p = start; p < end;) {
                        UChar32 cp;
                        U16_NEXT(text.utf16.data(), p, end, cp);
                        message << " U+" << std::hex << std::uppercase << cp;
                    }
                    message << "; attempted " << style.value("families", "registered font stack");
                    throw std::runtime_error(message.str());
                }
            }
            int orientation = u_getIntPropertyValue(text.at(start), UCHAR_VERTICAL_ORIENTATION);
            std::string mode = style.value("orientation", "mixed");
            bool sideways = vertical && (mode == "sideways" || (mode == "mixed" &&
                (orientation == U_VO_ROTATED || orientation == U_VO_TRANSFORMED_ROTATED)));
            result.push_back({start, end, span, chosen, script, sideways});
        }
        // Resolve initial punctuation from the following script.
        for (int i = int(result.size()) - 2; i >= 0; --i) {
            if (result[i].script == USCRIPT_COMMON) result[i].script = result[i + 1].script;
        }
        // Prefer one covering font over splitting a cursive/script run between fallbacks.
        for (size_t i = 0; i < result.size();) {
            size_t end = i + 1;
            while (end < result.size() && result[end].script == result[i].script &&
                   spans[result[end].span].style == spans[result[i].span].style) ++end;
            const auto &style = spans[result[i].span].style;
            if (!style.contains("object")) {
                for (const auto &id : style.at("fonts")) {
                    if (covers(getFont(id), text, result[i].start, result[end - 1].end)) {
                        for (size_t j = i; j < end; ++j) result[j].font = id;
                        break;
                    }
                }
            }
            i = end;
        }
        return result;
    }

    bool sameShape(const Span &a, const Span &b) {
        for (const char *key : {"size", "language", "features", "letterSpacing", "wordSpacing", "orientation"}) {
            if (a.style.value(key, Json()) != b.style.value(key, Json())) return false;
        }
        return !a.style.contains("object") && !b.style.contains("object");
    }

    Json shapeLine(const Text &text, const std::vector<Span> &spans, const std::vector<Cluster> &all,
                   const BidiParagraphs &paragraph, int start, int end, bool vertical) {
        Json items = Json::array();
        if (start == end) {
            const Span *span = &spans.back();
            for (const auto &candidate : spans) {
                if (start >= candidate.start && start < candidate.end) { span = &candidate; break; }
            }
            if (!span->style.contains("object")) {
                int id = span->style.at("fonts").at(0);
                Font &font = getFont(id);
                hb_font_extents_t metrics{};
                hb_font_get_h_extents(font.font, &metrics);
                double scale = span->style.value("size", 10.0) / font.upem;
                items.push_back({{"item", span->item}, {"font", id}, {"width", 0.0}, {"ascent", metrics.ascender * scale},
                    {"descent", -metrics.descender * scale}, {"lineGap", metrics.line_gap * scale},
                    {"glyphs", Json::array()}, {"vertical", vertical}, {"sideways", false}, {"direction", "ltr"}});
            }
            return {{"items", items}, {"width", 0.0}};
        }
        double width = 0;
        for (const auto &run : paragraph.runs(start, end)) {
            int runStart = run.start, runEnd = run.end;
            bool rtl = run.rtl;
            std::vector<std::vector<const Cluster *>> groups;
            auto first = std::lower_bound(all.begin(), all.end(), runStart,
                [](const Cluster &c, int p) { return c.end <= p; });
            for (auto it = first; it != all.end() && it->start < runEnd; ++it) {
                if (groups.empty() || groups.back().back()->font != it->font ||
                    groups.back().back()->script != it->script || groups.back().back()->sideways != it->sideways ||
                    !sameShape(spans[groups.back().back()->span], spans[it->span])) groups.emplace_back();
                groups.back().push_back(&*it);
            }
            if (rtl) std::reverse(groups.begin(), groups.end());
            for (const auto &group : groups) {
                const auto &firstCluster = *group.front();
                const auto &span = spans[firstCluster.span];
                const auto &style = span.style;
                int a = std::max(firstCluster.start, runStart), b = std::min(group.back()->end, runEnd);
                if (style.contains("object")) {
                    double objectWidth = style.at("object");
                    items.push_back({{"item", span.item}, {"width", objectWidth}, {"object", true}});
                    width += objectWidth;
                    continue;
                }
                Font &font = getFont(firstCluster.font);
                std::unique_ptr<hb_buffer_t, decltype(&hb_buffer_destroy)> buffer(hb_buffer_create(), hb_buffer_destroy);
                hb_buffer_set_cluster_level(buffer.get(), HB_BUFFER_CLUSTER_LEVEL_MONOTONE_GRAPHEMES);
                bool upright = vertical && !firstCluster.sideways;
                hb_buffer_set_direction(buffer.get(), upright ? HB_DIRECTION_TTB :
                    (rtl ? HB_DIRECTION_RTL : HB_DIRECTION_LTR));
                hb_buffer_set_script(buffer.get(), hb_script_from_string(uscript_getShortName(firstCluster.script), -1));
                std::string language = style.value("language", "und");
                hb_buffer_set_language(buffer.get(), hb_language_from_string(language.c_str(), -1));
                hb_buffer_flags_t flags = HB_BUFFER_FLAG_PRODUCE_UNSAFE_TO_CONCAT;
                if (a == start) flags = hb_buffer_flags_t(flags | HB_BUFFER_FLAG_BOT);
                if (b == end) flags = hb_buffer_flags_t(flags | HB_BUFFER_FLAG_EOT);
                hb_buffer_set_flags(buffer.get(), flags);
                // Context is limited to the line; joining is retained across compatible styled spans.
                hb_buffer_add_utf8(buffer.get(), text.utf8.data() + text.bytes[start], text.bytes[end] - text.bytes[start],
                    text.bytes[a] - text.bytes[start], text.bytes[b] - text.bytes[a]);
                std::vector<hb_feature_t> features;
                for (const auto &entry : style.value("features", Json::array())) {
                    hb_feature_t feature;
                    std::string value = entry;
                    require(hb_feature_from_string(value.c_str(), -1, &feature), "Invalid OpenType feature");
                    features.push_back(feature);
                }
                double spacing = style.value("letterSpacing", 0.0);
                if (spacing != 0) {
                    features.push_back({HB_TAG('l','i','g','a'), 0, 0, unsigned(-1)});
                    features.push_back({HB_TAG('c','l','i','g'), 0, 0, unsigned(-1)});
                }
                hb_shape(font.font, buffer.get(), features.data(), features.size());
                unsigned length;
                auto info = hb_buffer_get_glyph_infos(buffer.get(), &length);
                auto positions = hb_buffer_get_glyph_positions(buffer.get(), nullptr);
                std::set<int> ends{text.bytes[b]};
                for (unsigned i = 0; i < length; ++i) ends.insert(info[i].cluster + text.bytes[start]);
                double scale = style.value("size", 10.0) / font.upem;
                Json current;
                for (unsigned i = 0; i < length; ++i) {
                    int byte = info[i].cluster + text.bytes[start];
                    auto next = ends.upper_bound(byte);
                    int clusterEnd = next == ends.end() ? text.bytes[b] : *next;
                    auto owner = std::upper_bound(group.begin(), group.end(), byte,
                        [&text](int p, const Cluster *c) { return p < text.bytes[c->start]; });
                    const Cluster &cluster = **(owner == group.begin() ? owner : owner - 1);
                    const Span &paint = spans[cluster.span];
                    bool clusterLast = i + 1 == length || info[i + 1].cluster != info[i].cluster;
                    std::string source = text.utf8.substr(byte, clusterEnd - byte);
                    bool visible = false;
                    Text sourceText(source);
                    for (int p = 0; p < sourceText.length();) {
                        UChar32 cp;
                        U16_NEXT(sourceText.utf16.data(), p, sourceText.length(), cp);
                        visible |= !u_hasBinaryProperty(cp, UCHAR_DEFAULT_IGNORABLE_CODE_POINT) && !u_iscntrl(cp);
                    }
                    require(info[i].codepoint != 0 || !visible, "Font shaping produced a missing glyph");
                    double advance = (upright ? -positions[i].y_advance : positions[i].x_advance) * scale;
                    bool joining = cluster.script == USCRIPT_ARABIC || cluster.script == USCRIPT_SYRIAC || cluster.script == USCRIPT_MONGOLIAN;
                    if (clusterLast && visible && !joining) advance += spacing;
                    if (clusterLast && source == " ") advance += style.value("wordSpacing", 0.0);
                    if (current.is_null() || current["item"] != paint.item) {
                        if (!current.is_null()) items.push_back(current);
                        hb_font_extents_t metrics{};
                        hb_font_get_h_extents(font.font, &metrics);
                        current = {{"item", paint.item}, {"font", firstCluster.font}, {"width", 0.0},
                            {"ascent", metrics.ascender * scale}, {"descent", -metrics.descender * scale},
                            {"lineGap", metrics.line_gap * scale}, {"glyphs", Json::array()},
                            {"sideways", firstCluster.sideways}, {"vertical", upright},
                            {"direction", rtl ? "rtl" : "ltr"}};
                    }
                    hb_glyph_extents_t ink{};
                    hb_font_get_glyph_extents(font.font, info[i].codepoint, &ink);
                    current["glyphs"].push_back({{"gid", info[i].codepoint}, {"cluster", byte}, {"end", clusterEnd},
                        {"text", source}, {"advance", advance},
                        {"ax", positions[i].x_advance * scale}, {"ay", positions[i].y_advance * scale},
                        {"dx", positions[i].x_offset * scale}, {"dy", positions[i].y_offset * scale},
                        {"ink", {ink.x_bearing * scale, ink.y_bearing * scale, ink.width * scale, ink.height * scale}},
                        {"unsafe", bool(hb_glyph_info_get_glyph_flags(&info[i]) & HB_GLYPH_FLAG_UNSAFE_TO_BREAK)},
                        {"space", clusterLast && source == " "}});
                    current["width"] = current["width"].get<double>() + advance;
                    width += advance;
                }
                if (!current.is_null()) items.push_back(current);
            }
        }
        return {{"items", items}, {"width", width}};
    }

    Json layout(const Json &request) {
        Text text(request.at("text"));
        if (!text.length()) return {{"lines", Json::array()}};
        std::vector<Span> spans;
        for (const auto &s : request.at("spans")) {
            int byteStart = s.at("start"), byteEnd = s.at("end");
            auto a = std::lower_bound(text.bytes.begin(), text.bytes.end(), byteStart);
            auto b = std::lower_bound(text.bytes.begin(), text.bytes.end(), byteEnd);
            require(a != text.bytes.end() && b != text.bytes.end() && *a == byteStart && *b == byteEnd,
                    "Style range is not a Unicode boundary");
            spans.push_back({int(a - text.bytes.begin()), int(b - text.bytes.begin()), s.at("item"), s.at("style")});
        }
        require(!spans.empty() && spans.front().start == 0 && spans.back().end == text.length(), "Incomplete style ranges");
        for (size_t i = 1; i < spans.size(); ++i) require(spans[i].start == spans[i-1].end, "Noncontiguous style ranges");
        std::string language = request.value("language", "und");
        bool vertical = request.value("vertical", false);
        auto graphemes = boundaries(text, UBRK_CHARACTER, language);
        auto all = clusters(text, spans, graphemes, vertical);
        std::string direction = request.value("direction", "ltr");
        BidiParagraphs bidi(text, direction == "auto" ? 254 : (direction == "rtl" ? 1 : 0));
        std::set<int> breaks, emergency, mandatory;
        for (const auto &span : spans) {
            std::string locale = span.style.value("language", language);
            std::string rule = span.style.value("lineBreak", "auto");
            if (rule == "strict" || rule == "loose" || rule == "normal") locale += "@lb=" + rule;
            auto opportunities = boundaries(text, UBRK_LINE, locale);
            std::string wordBreak = span.style.value("wordBreak", "normal");
            bool wrap = span.style.value("wrap", true);
            if (wrap) {
                for (int p : opportunities) {
                    if (p <= span.start || p > span.end) continue;
                    bool keep = wordBreak == "keep-all" && p < text.length() && !u_isUWhiteSpace(text.at(p - 1));
                    if (!keep) breaks.insert(p);
                }
                for (int p : graphemes) {
                    if (p <= span.start || p > span.end) continue;
                    if (wordBreak == "break-all" || rule == "anywhere") breaks.insert(p);
                    std::string overflow = span.style.value("overflowWrap", "normal");
                    if (overflow == "anywhere" || overflow == "break-word" || wordBreak == "break-word") emergency.insert(p);
                }
            }
        }
        for (int p = 0; p < text.length(); ++p) {
            if (text.utf16[p] == '\n' || text.utf16[p] == 0x2028 || text.utf16[p] == 0x2029) {
                mandatory.insert(p + 1); breaks.insert(p + 1);
            }
        }
        breaks.insert(text.length());
        // One paragraph shape supplies cumulative advances; only final line fragments are reshaped.
        Json full = {{"items", Json::array()}};
        for (auto [paragraphStart, paragraphEnd] : bidi.ranges()) {
            auto shaped = shapeLine(text, spans, all, bidi, paragraphStart, paragraphEnd, vertical);
            for (auto &item : shaped["items"]) full["items"].push_back(std::move(item));
        }
        std::map<int, double> advances;
        std::set<int> unsafe;
        for (const auto &item : full["items"]) {
            if (item.value("object", false)) {
                for (const auto &span : spans) if (span.item == item["item"]) advances[text.bytes[span.start]] += item["width"].get<double>();
            } else {
                for (const auto &glyph : item["glyphs"]) {
                    advances[glyph["cluster"].get<int>()] += glyph["advance"].get<double>();
                    if (glyph["unsafe"].get<bool>()) unsafe.insert(glyph["cluster"].get<int>());
                }
            }
        }
        for (auto it = emergency.begin(); it != emergency.end();) {
            if (unsafe.count(text.bytes[*it]) || (!advances.count(text.bytes[*it]) && *it != text.length())) it = emergency.erase(it);
            else ++it;
        }
        double maxWidth = request.value("width", 1e9);
        require(std::isfinite(maxWidth) && maxWidth >= 0, "Invalid line width");
        Json lines = Json::array();
        int start = 0;
        while (start < text.length()) {
            int candidate = start, mandatoryEnd = text.length();
            auto hard = mandatory.upper_bound(start);
            if (hard != mandatory.end()) mandatoryEnd = *hard;
            double used = 0;
            for (auto it = advances.lower_bound(text.bytes[start]); it != advances.end() && it->first < text.bytes[mandatoryEnd]; ++it) {
                used += it->second;
                auto next = std::next(it);
                int byteEnd = next == advances.end() ? int(text.utf8.size()) : next->first;
                int p = int(std::lower_bound(text.bytes.begin(), text.bytes.end(), byteEnd) - text.bytes.begin());
                p = std::min(p, mandatoryEnd);
                bool trailingSpace = p > start && text.utf16[p - 1] == ' ';
                if (used <= maxWidth + .01 || trailingSpace) {
                    if (breaks.count(p)) candidate = p;
                } else break;
            }
            if (candidate == start) {
                used = 0;
                for (auto it = advances.lower_bound(text.bytes[start]); it != advances.end() && it->first < text.bytes[mandatoryEnd]; ++it) {
                    used += it->second;
                    auto next = std::next(it);
                    int byteEnd = next == advances.end() ? int(text.utf8.size()) : next->first;
                    int p = int(std::lower_bound(text.bytes.begin(), text.bytes.end(), byteEnd) - text.bytes.begin());
                    if (used > maxWidth + .01) break;
                    if (emergency.count(p)) candidate = p;
                }
                if (candidate == start) candidate = std::min(*breaks.upper_bound(start), mandatoryEnd);
            }
            candidate = std::min(candidate, mandatoryEnd);
            int visibleEnd = candidate;
            while (visibleEnd > start && (text.utf16[visibleEnd-1] == '\n' || text.utf16[visibleEnd-1] == 0x2028 || text.utf16[visibleEnd-1] == 0x2029)) --visibleEnd;
            auto preserves = [&](int p) {
                for (const auto &span : spans) if (p >= span.start && p < span.end) return span.style.value("preserve", false);
                return false;
            };
            while (visibleEnd > start && text.utf16[visibleEnd-1] == ' ' && !preserves(visibleEnd-1)) --visibleEnd;
            auto line = shapeLine(text, spans, all, bidi, start, visibleEnd, vertical);
            while (line["width"].get<double>() > maxWidth + .01) {
                auto before = breaks.lower_bound(candidate);
                if (before == breaks.begin() || *std::prev(before) <= start) break;
                candidate = *std::prev(before);
                visibleEnd = candidate;
                while (visibleEnd > start && text.utf16[visibleEnd-1] == ' ' && !preserves(visibleEnd-1)) --visibleEnd;
                line = shapeLine(text, spans, all, bidi, start, visibleEnd, vertical);
            }
            line["text"] = text.slice(start, visibleEnd);
            line["start"] = text.bytes[start];
            line["end"] = text.bytes[visibleEnd];
            line["hard"] = mandatory.count(candidate) || candidate == text.length();
            line["direction"] = bidi.baseLevel(start) ? "rtl" : "ltr";
            lines.push_back(line);
            start = candidate;
            while (start < text.length() && text.utf16[start] == ' ' && !preserves(start)) ++start;
        }
        return {{"lines", lines}, {"graphemes", graphemes}, {"utf16ToByte", text.bytes}, {"utf16ToCharacter", text.characters}};
    }

    Json subset(const Json &request);
    Json colorGlyph(const Json &request);

public:
    Engine() { require(!FT_Init_FreeType(&library), "Cannot initialize FreeType"); }
    ~Engine() { fonts.clear(); FT_Done_FreeType(library); }
    Json dispatch(const Json &request) {
        std::string op = request.at("op");
        if (op == "capabilities") {
            UVersionInfo unicode;
            u_getUnicodeVersion(unicode);
            char unicodeVersion[U_MAX_VERSION_STRING_LENGTH];
            u_versionToString(unicode, unicodeVersion);
            return {{"version", "1.1.0"}, {"protocol", 1}, {"harfbuzz", hb_version_string()},
                {"icu", U_ICU_VERSION}, {"fribidi", FRIBIDI_VERSION}, {"unicode", unicodeVersion}, {"maxMessage", MAX_MESSAGE},
                {"capabilities", {"layout", "bidi", "graphemes", "fallback", "subset", "variations", "vertical", "color"}}};
        }
        if (op == "font") return registerFont(request);
        if (op == "layout") return layout(request);
        if (op == "subset") return subset(request);
        if (op == "color") return colorGlyph(request);
        if (op == "analyze") {
            Text text(request.at("text"));
            auto language = request.value("language", "und");
            int base = request.value("baseLevel", 254);
            require(base == 0 || base == 1 || base == 254 || base == 255, "Invalid bidi base level");
            BidiParagraphs bidi(text, base);
            auto result = bidi.analysis();
            result["graphemes"] = boundaries(text, UBRK_CHARACTER, language);
            result["breaks"] = boundaries(text, UBRK_LINE, language);
            result["utf16ToByte"] = text.bytes;
            result["utf16ToCharacter"] = text.characters;
            return result;
        }
        if (op == "batch") {
            const auto &requests = request.at("requests");
            require(requests.is_array() && requests.size() <= 4096, "Invalid batch size");
            Json results = Json::array();
            for (const auto &entry : requests) {
                require(entry.at("op") != "batch", "Nested batches are not supported");
                results.push_back(dispatch(entry));
            }
            return results;
        }
        throw std::runtime_error("Unknown operation: " + op);
    }
};

// Font embedding and color rasterization are implemented below.
#include "output.h"

int main(int argc, char **argv) {
    try {
        ProcessIo io(argc, argv);
        Engine engine;
        while (true) {
            unsigned char header[4];
            auto received = io.read(reinterpret_cast<char *>(header), 4);
            if (received == 0) break;
            require(received == 4, "Truncated request header");
            uint32_t length = uint32_t(header[0]) << 24 | uint32_t(header[1]) << 16 | uint32_t(header[2]) << 8 | header[3];
            require(length > 0 && length <= MAX_MESSAGE, "Invalid request size");
            std::string payload(length, '\0');
            require(io.read(payload.data(), length) == length, "Truncated request");
            Json response, id = nullptr;
            try {
                auto request = Json::parse(payload);
                id = request.at("id");
                require(request.at("protocol") == 1, "Incompatible protocol; expected 1");
                response = {{"protocol", 1}, {"id", id}, {"result", engine.dispatch(request)}};
            } catch (const std::exception &error) {
                response = {{"protocol", 1}, {"id", id}, {"error", {{"code", "text_error"}, {"message", error.what()}}}};
            }
            auto body = response.dump();
            require(body.size() <= MAX_MESSAGE, "Response exceeds message limit");
            uint32_t size = body.size();
            unsigned char prefix[] = {static_cast<unsigned char>(size >> 24), static_cast<unsigned char>(size >> 16),
                static_cast<unsigned char>(size >> 8), static_cast<unsigned char>(size)};
            if (!io.write(reinterpret_cast<char *>(prefix), 4) || !io.write(body.data(), body.size())) break;
        }
    } catch (const std::exception &error) {
        std::cerr << "tinypdf-text: " << error.what() << '\n';
        return 1;
    }
}
