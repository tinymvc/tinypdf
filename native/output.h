// Emit static CID-keyed CFF outlines for CFF/CFF2, including name-keyed input.
// HarfBuzz resolves subroutines and variable outlines without rounding design coordinates.
static void append16(std::string &out, unsigned value) {
    out += char(value >> 8);
    out += char(value);
}

static void append32(std::string &out, uint32_t value) {
    out += char(value >> 24);
    out += char(value >> 16);
    out += char(value >> 8);
    out += char(value);
}

static std::string cffIndex(const std::vector<std::string> &entries) {
    std::string out;
    append16(out, entries.size());
    if (entries.empty()) return out;
    out += char(4);
    uint32_t offset = 1;
    append32(out, offset);
    for (const auto &entry : entries) { offset += entry.size(); append32(out, offset); }
    for (const auto &entry : entries) out += entry;
    return out;
}

static void dictNumber(std::string &out, int value) {
    out += char(29);
    append32(out, uint32_t(value));
}

static void type2Number(std::string &out, double value) {
    require(std::isfinite(value) && std::abs(value) < 32768, "CFF coordinate exceeds Type 2 range");
    out += char(255);
    append32(out, uint32_t(int32_t(std::lround(value * 65536))));
}

struct Outline {
    std::string data;
    double scale, x = 0, y = 0;
    void delta(double nextX, double nextY) {
        type2Number(data, nextX - x);
        type2Number(data, nextY - y);
        x = nextX; y = nextY;
    }
    static void move(hb_draw_funcs_t *, void *user, hb_draw_state_t *, float x, float y, void *) {
        auto &o = *static_cast<Outline *>(user);
        o.delta(x * o.scale, y * o.scale);
        o.data += char(21);
    }
    static void line(hb_draw_funcs_t *, void *user, hb_draw_state_t *, float x, float y, void *) {
        auto &o = *static_cast<Outline *>(user);
        o.delta(x * o.scale, y * o.scale);
        o.data += char(5);
    }
    static void cubic(hb_draw_funcs_t *, void *user, hb_draw_state_t *,
                      float ax, float ay, float bx, float by, float x, float y, void *) {
        auto &o = *static_cast<Outline *>(user);
        o.delta(ax * o.scale, ay * o.scale);
        o.delta(bx * o.scale, by * o.scale);
        o.delta(x * o.scale, y * o.scale);
        o.data += char(8);
    }

};

static std::string cidCff(Font &font, const Json &glyphs) {
    std::vector<std::string> charstrings;
    std::unique_ptr<hb_draw_funcs_t, decltype(&hb_draw_funcs_destroy)> callbacks(hb_draw_funcs_create(), hb_draw_funcs_destroy);
    hb_draw_funcs_set_move_to_func(callbacks.get(), Outline::move, nullptr, nullptr);
    hb_draw_funcs_set_line_to_func(callbacks.get(), Outline::line, nullptr, nullptr);
    hb_draw_funcs_set_cubic_to_func(callbacks.get(), Outline::cubic, nullptr, nullptr);
    for (size_t cid = 0; cid <= glyphs.size(); ++cid) {
        unsigned gid = cid == 0 ? 0 : glyphs[cid - 1].at("gid").get<unsigned>();
        if (cid) require(glyphs[cid - 1].at("cid") == cid, "CIDs must be contiguous");
        Outline outline{{}, 1000.0 / font.upem};
        double advance = hb_font_get_glyph_h_advance(font.font, gid) * outline.scale;
        type2Number(outline.data, advance);
        require(hb_font_draw_glyph_or_fail(font.font, gid, callbacks.get(), &outline) || font.color,
                "Cannot load glyph outline for embedding");
        outline.data += char(14);
        charstrings.push_back(outline.data);
    }
    std::string charset(1, '\0'), fdSelect(1, '\0');
    for (size_t cid = 1; cid < charstrings.size(); ++cid) append16(charset, cid);
    fdSelect.append(charstrings.size(), '\0');
    std::string strings = cffIndex({"Adobe", "Identity"});
    std::string names = cffIndex({"TinyPDFSubset"});
    std::string globalSubrs = cffIndex({});
    std::string chars = cffIndex(charstrings);
    auto topDict = [&](int charsetOffset, int charsOffset, int fdArrayOffset, int fdSelectOffset) {
        std::string top;
        dictNumber(top, 391); dictNumber(top, 392); dictNumber(top, 0); top += char(12); top += char(30);
        dictNumber(top, charstrings.size()); top += char(12); top += char(34);
        dictNumber(top, charsetOffset); top += char(15);
        dictNumber(top, charsOffset); top += char(17);
        dictNumber(top, fdArrayOffset); top += char(12); top += char(36);
        dictNumber(top, fdSelectOffset); top += char(12); top += char(37);
        return cffIndex({top});
    };
    int offset = 4 + names.size() + topDict(0, 0, 0, 0).size() + strings.size() + globalSubrs.size();
    int charsetOffset = offset;
    int charsOffset = charsetOffset + charset.size();
    int fdSelectOffset = charsOffset + chars.size();
    int fdArrayOffset = fdSelectOffset + fdSelect.size();
    return std::string("\1\0\4\4", 4) + names + topDict(charsetOffset, charsOffset, fdArrayOffset, fdSelectOffset)
        + strings + globalSubrs + charset + chars + fdSelect + cffIndex({std::string()});
}

Json Engine::subset(const Json &request) {
    Font &font = getFont(request.at("font"));
    const auto &glyphs = request.at("glyphs");
    require(glyphs.size() < 65535, "PDF font exceeds 65534 CIDs");
    Json mapping = Json::object(), widths = Json::object();
    for (const auto &g : glyphs) {
        unsigned gid = g.at("gid");
        require(gid < hb_face_get_glyph_count(font.face), "Invalid glyph ID");
        widths[std::to_string(gid)] = hb_font_get_glyph_h_advance(font.font, gid) * 1000.0 / font.upem;
    }
    if (!font.truetype) {
        auto data = cidCff(font, glyphs);
        return {{"format", "cff"}, {"data", base64(reinterpret_cast<const unsigned char *>(data.data()), data.size())},
            {"widths", widths}, {"mapping", mapping}};
    }
    std::unique_ptr<hb_subset_input_t, decltype(&hb_subset_input_destroy)> input(hb_subset_input_create_or_fail(), hb_subset_input_destroy);
    require(bool(input), "Cannot allocate subset input");
    hb_subset_input_set_flags(input.get(), HB_SUBSET_FLAGS_NOTDEF_OUTLINE | HB_SUBSET_FLAGS_NO_HINTING);
    hb_set_clear(hb_subset_input_unicode_set(input.get()));
    auto set = hb_subset_input_glyph_set(input.get());
    hb_set_add(set, 0);
    for (const auto &glyph : glyphs) hb_set_add(set, glyph.at("gid"));
    if (!font.variations.empty()) {
        require(hb_subset_input_pin_all_axes_to_default(input.get(), font.face), "Cannot pin font axes");
    }
    for (const auto &axis : font.variations) {
        require(hb_subset_input_pin_axis_location(input.get(), font.face, axis.tag, axis.value), "Cannot instantiate variable font");
    }
    std::unique_ptr<hb_subset_plan_t, decltype(&hb_subset_plan_destroy)> plan(
        hb_subset_plan_create_or_fail(font.face, input.get()), hb_subset_plan_destroy);
    require(bool(plan), "Cannot plan font subset");
    auto map = hb_subset_plan_old_to_new_glyph_mapping(plan.get());
    for (const auto &glyph : glyphs) {
        unsigned gid = glyph.at("gid");
        require(hb_map_has(map, gid), "Subset lost a used glyph");
        mapping[std::to_string(gid)] = hb_map_get(map, gid);
    }
    std::unique_ptr<hb_face_t, decltype(&hb_face_destroy)> face(hb_subset_plan_execute_or_fail(plan.get()), hb_face_destroy);
    require(bool(face), "Cannot subset font");
    std::unique_ptr<hb_blob_t, decltype(&hb_blob_destroy)> blob(hb_face_reference_blob(face.get()), hb_blob_destroy);
    unsigned size;
    auto data = hb_blob_get_data(blob.get(), &size);
    require(size > 0, "Empty font subset");
    return {{"format", "truetype"}, {"data", base64(reinterpret_cast<const unsigned char *>(data), size)},
        {"widths", widths}, {"mapping", mapping}};
}

Json Engine::colorGlyph(const Json &request) {
    Font &font = getFont(request.at("font"));
    unsigned gid = request.at("gid");
    require(gid < hb_face_get_glyph_count(font.face), "Invalid color glyph ID");
    if (!font.color) return nullptr;
    auto png = hb_ot_color_glyph_reference_png(font.font, gid);
    auto svg = hb_ot_color_glyph_reference_svg(font.face, gid);
    unsigned layers = hb_ot_color_glyph_get_layers(font.face, gid, 0, nullptr, nullptr);
    bool colored = hb_ot_color_glyph_has_paint(font.face, gid) || layers || hb_blob_get_length(png) || hb_blob_get_length(svg);
    std::unique_ptr<hb_blob_t, decltype(&hb_blob_destroy)> pngBlob(png, hb_blob_destroy), svgBlob(svg, hb_blob_destroy);
    if (!colored) return nullptr;
    double size = request.at("size"), dpi = request.value("dpi", 600.0);
    require(std::isfinite(size) && size > 0 && std::isfinite(dpi) && dpi >= 72 && dpi <= 2400, "Invalid color glyph size/DPI");
    double pixelsPerPoint = dpi / 72.0;
    cairo_font_face_t *face = hb_cairo_font_face_create_for_font(font.font);
    cairo_matrix_t matrix, ctm;
    cairo_matrix_init_scale(&matrix, size * pixelsPerPoint, size * pixelsPerPoint);
    cairo_matrix_init_identity(&ctm);
    auto options = cairo_font_options_create();
    cairo_font_options_set_hint_style(options, CAIRO_HINT_STYLE_NONE);
    cairo_font_options_set_hint_metrics(options, CAIRO_HINT_METRICS_OFF);
    std::unique_ptr<cairo_scaled_font_t, decltype(&cairo_scaled_font_destroy)> scaledOwner(
        cairo_scaled_font_create(face, &matrix, &ctm, options), cairo_scaled_font_destroy);
    auto scaled = scaledOwner.get();
    cairo_font_options_destroy(options);
    cairo_font_face_destroy(face);
    cairo_glyph_t glyph{gid, 0, 0};
    cairo_text_extents_t extents{};
    cairo_scaled_font_glyph_extents(scaled, &glyph, 1, &extents);
    std::unique_ptr<RsvgHandle, decltype(&g_object_unref)> svgHandle(nullptr, g_object_unref);
    std::unique_ptr<cairo_surface_t, decltype(&cairo_surface_destroy)> bitmap(nullptr, cairo_surface_destroy);
    std::string svgId = "#glyph" + std::to_string(gid);
    RsvgRectangle viewport{0, 0, double(font.upem), double(font.upem)};
    if (hb_blob_get_length(svg)) {
        unsigned svgSize;
        auto svgData = hb_blob_get_data(svg, &svgSize);
        GError *error = nullptr;
        // No base URI is set: SVG glyphs cannot resolve external resources.
        svgHandle.reset(rsvg_handle_new_from_data(reinterpret_cast<const guint8 *>(svgData), svgSize, &error));
        if (error) g_error_free(error);
        require(bool(svgHandle), "Cannot decode OpenType SVG glyph");
        if (!rsvg_handle_has_sub(svgHandle.get(), svgId.c_str())) svgId.clear();
        RsvgRectangle ink{}, logical{};
        require(rsvg_handle_get_geometry_for_layer(svgHandle.get(), svgId.empty() ? nullptr : svgId.c_str(),
                    &viewport, &ink, &logical, nullptr), "Cannot measure OpenType SVG glyph");
        double scale = size * pixelsPerPoint / font.upem;
        extents.x_bearing = ink.x * scale;
        extents.y_bearing = ink.y * scale;
        extents.width = ink.width * scale;
        extents.height = ink.height * scale;
    } else if (hb_blob_get_length(png)) {
        struct Reader { const char *data; unsigned size; unsigned offset; };
        unsigned pngSize;
        auto pngData = hb_blob_get_data(png, &pngSize);
        Reader reader{pngData, pngSize, 0};
        bitmap.reset(cairo_image_surface_create_from_png_stream([](void *closure, unsigned char *out, unsigned length) {
            auto &r = *static_cast<Reader *>(closure);
            if (length > r.size - r.offset) return CAIRO_STATUS_READ_ERROR;
            std::memcpy(out, r.data + r.offset, length);
            r.offset += length;
            return CAIRO_STATUS_SUCCESS;
        }, &reader));
        require(cairo_surface_status(bitmap.get()) == CAIRO_STATUS_SUCCESS, "Cannot decode color glyph PNG");
        hb_glyph_extents_t ink{};
        require(hb_font_get_glyph_extents(font.font, gid, &ink), "Cannot measure bitmap glyph");
        double scale = size * pixelsPerPoint / font.upem;
        extents.x_bearing = ink.x_bearing * scale;
        extents.y_bearing = -ink.y_bearing * scale;
        extents.width = ink.width * scale;
        extents.height = -ink.height * scale;
    }
    double left = std::floor(extents.x_bearing) - 1, top = std::floor(extents.y_bearing) - 1;
    double rasterWidth = std::ceil(extents.x_bearing + extents.width) - left + 1;
    double rasterHeight = std::ceil(extents.y_bearing + extents.height) - top + 1;
    require(std::isfinite(rasterWidth) && std::isfinite(rasterHeight) && rasterWidth > 0 && rasterHeight > 0
            && rasterWidth <= 8192 && rasterHeight <= 8192 && rasterWidth * rasterHeight <= 16777216,
            "Color glyph raster exceeds limits");
    int width = int(rasterWidth), height = int(rasterHeight);
    std::unique_ptr<cairo_surface_t, decltype(&cairo_surface_destroy)> surfaceOwner(
        cairo_image_surface_create(CAIRO_FORMAT_ARGB32, width, height), cairo_surface_destroy);
    auto surface = surfaceOwner.get();
    std::unique_ptr<cairo_t, decltype(&cairo_destroy)> crOwner(cairo_create(surface), cairo_destroy);
    auto cr = crOwner.get();
    cairo_set_scaled_font(cr, scaled);
    auto color = request.value("color", std::vector<double>{0, 0, 0});
    require(color.size() >= 3, "Invalid foreground color");
    cairo_set_source_rgb(cr, color[0], color[1], color[2]);
    glyph.x = -left; glyph.y = -top;
    if (svgHandle) {
        cairo_translate(cr, glyph.x, glyph.y);
        double scale = size * pixelsPerPoint / font.upem;
        cairo_scale(cr, scale, scale);
        require(rsvg_handle_render_layer(svgHandle.get(), cr, svgId.empty() ? nullptr : svgId.c_str(), &viewport, nullptr),
                "Cannot render OpenType SVG glyph");
    } else if (bitmap) {
        cairo_translate(cr, glyph.x + extents.x_bearing, glyph.y + extents.y_bearing);
        cairo_scale(cr, extents.width / cairo_image_surface_get_width(bitmap.get()),
                        extents.height / cairo_image_surface_get_height(bitmap.get()));
        cairo_set_source_surface(cr, bitmap.get(), 0, 0);
        cairo_paint(cr);
    } else {
        cairo_show_glyphs(cr, &glyph, 1);
    }
    auto status = cairo_status(cr);
    cairo_surface_flush(surface);
    require(status == CAIRO_STATUS_SUCCESS, "Color rendering failed");
    std::string rgb, alpha;
    rgb.reserve(width * height * 3); alpha.reserve(width * height);
    auto data = cairo_image_surface_get_data(surface);
    int stride = cairo_image_surface_get_stride(surface);
    for (int y = 0; y < height; ++y) {
        auto row = reinterpret_cast<uint32_t *>(data + y * stride);
        for (int x = 0; x < width; ++x) {
            unsigned a = row[x] >> 24;
            alpha += char(a);
            for (int shift : {16, 8, 0}) rgb += char(a ? std::min(255u, ((row[x] >> shift) & 255) * 255 / a) : 0);
        }
    }
    return {{"width", width}, {"height", height}, {"left", left / pixelsPerPoint}, {"top", top / pixelsPerPoint},
        {"w", width / pixelsPerPoint}, {"h", height / pixelsPerPoint},
        {"rgb", base64(reinterpret_cast<const unsigned char *>(rgb.data()), rgb.size())},
        {"alpha", base64(reinterpret_cast<const unsigned char *>(alpha.data()), alpha.size())}};
}
