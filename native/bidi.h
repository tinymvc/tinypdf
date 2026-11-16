// Use ICU's versioned Unicode properties with FriBidi's UAX #9 resolver.
// ICU 78's resolver fails the override/isolate cases in BidiCharacterTest.
struct BidiRun { int start, end; bool rtl; };

class BidiParagraphs {
    struct Paragraph { int start, end; FriBidiParType direction; };
    std::vector<FriBidiCharType> types;
    std::vector<FriBidiLevel> levels;
    std::vector<int> offsets;
    std::vector<Paragraph> paragraphs;

    static FriBidiCharType type(UChar32 cp) {
        switch (u_charDirection(cp)) {
            case U_LEFT_TO_RIGHT: return FRIBIDI_TYPE_LTR;
            case U_RIGHT_TO_LEFT: return FRIBIDI_TYPE_RTL;
            case U_RIGHT_TO_LEFT_ARABIC: return FRIBIDI_TYPE_AL;
            case U_EUROPEAN_NUMBER: return FRIBIDI_TYPE_EN;
            case U_EUROPEAN_NUMBER_SEPARATOR: return FRIBIDI_TYPE_ES;
            case U_EUROPEAN_NUMBER_TERMINATOR: return FRIBIDI_TYPE_ET;
            case U_ARABIC_NUMBER: return FRIBIDI_TYPE_AN;
            case U_COMMON_NUMBER_SEPARATOR: return FRIBIDI_TYPE_CS;
            case U_DIR_NON_SPACING_MARK: return FRIBIDI_TYPE_NSM;
            case U_BOUNDARY_NEUTRAL: return FRIBIDI_TYPE_BN;
            case U_BLOCK_SEPARATOR: return FRIBIDI_TYPE_BS;
            case U_SEGMENT_SEPARATOR: return FRIBIDI_TYPE_SS;
            case U_WHITE_SPACE_NEUTRAL: return FRIBIDI_TYPE_WS;
            case U_LEFT_TO_RIGHT_EMBEDDING: return FRIBIDI_TYPE_LRE;
            case U_LEFT_TO_RIGHT_OVERRIDE: return FRIBIDI_TYPE_LRO;
            case U_RIGHT_TO_LEFT_EMBEDDING: return FRIBIDI_TYPE_RLE;
            case U_RIGHT_TO_LEFT_OVERRIDE: return FRIBIDI_TYPE_RLO;
            case U_POP_DIRECTIONAL_FORMAT: return FRIBIDI_TYPE_PDF;
            case U_LEFT_TO_RIGHT_ISOLATE: return FRIBIDI_TYPE_LRI;
            case U_RIGHT_TO_LEFT_ISOLATE: return FRIBIDI_TYPE_RLI;
            case U_FIRST_STRONG_ISOLATE: return FRIBIDI_TYPE_FSI;
            case U_POP_DIRECTIONAL_ISOLATE: return FRIBIDI_TYPE_PDI;
            default: return FRIBIDI_TYPE_ON;
        }
    }

    const Paragraph &paragraphAt(int position) const {
        for (const auto &p : paragraphs) if (position >= p.start && position < p.end) return p;
        return paragraphs.back();
    }

    std::pair<std::vector<FriBidiStrIndex>, std::vector<FriBidiLevel>> order(int start, int end) const {
        auto lineLevels = levels;
        std::vector<FriBidiStrIndex> map(levels.size());
        for (size_t i = 0; i < map.size(); ++i) map[i] = i;
        for (const auto &p : paragraphs) {
            int a = std::max(start, p.start), b = std::min(end, p.end);
            if (a >= b) continue;
            // L3 (mark ordering) and L4 (mirroring) belong to HarfBuzz, not this map.
            require(fribidi_reorder_line(0, types.data() + p.start, b - a, a - p.start,
                p.direction, lineLevels.data() + p.start, nullptr, map.data() + p.start), "Bidi line reordering failed");
        }
        return {map, lineLevels};
    }

public:
    BidiParagraphs(const Text &text, int base) {
        std::vector<FriBidiBracketType> brackets;
        for (int p = 0; p < text.length();) {
            offsets.push_back(p);
            UChar32 cp;
            U16_NEXT(text.utf16.data(), p, text.length(), cp);
            types.push_back(type(cp));
            int bracketType = u_getIntPropertyValue(cp, UCHAR_BIDI_PAIRED_BRACKET_TYPE);
            UChar32 paired = bracketType == U_BPT_OPEN ? cp : u_getBidiPairedBracket(cp);
            if (paired == 0x2329) paired = 0x3008; // Canonical bracket equivalence in BD16.
            brackets.push_back(bracketType == U_BPT_NONE ? 0 :
                unsigned(paired) | (bracketType == U_BPT_OPEN ? FRIBIDI_BRACKET_OPEN_MASK : 0));
        }
        offsets.push_back(text.length());
        levels.resize(types.size());
        int start = 0;
        for (int i = 0; i < int(types.size()); ++i) {
            if (types[i] != FRIBIDI_TYPE_BS && i + 1 != int(types.size())) continue;
            FriBidiParType direction = base == 0 ? FRIBIDI_PAR_LTR : base == 1 ? FRIBIDI_PAR_RTL :
                base == 255 ? FRIBIDI_PAR_WRTL : FRIBIDI_PAR_WLTR;
            require(fribidi_get_par_embedding_levels_ex(types.data() + start, brackets.data() + start,
                i + 1 - start, &direction, levels.data() + start), "Bidi paragraph analysis failed");
            paragraphs.push_back({start, i + 1, direction});
            start = i + 1;
        }
    }

    std::vector<std::pair<int, int>> ranges() const {
        std::vector<std::pair<int, int>> result;
        for (const auto &p : paragraphs) result.push_back({offsets[p.start], offsets[p.end]});
        return result;
    }

    int baseLevel(int utf16 = 0) const {
        if (paragraphs.empty()) return 0;
        int p = int(std::lower_bound(offsets.begin(), offsets.end(), utf16) - offsets.begin());
        return FRIBIDI_IS_RTL(paragraphAt(p).direction) ? 1 : 0;
    }

    std::vector<BidiRun> runs(int utf16Start, int utf16End) const {
        int a = int(std::lower_bound(offsets.begin(), offsets.end(), utf16Start) - offsets.begin());
        int b = int(std::lower_bound(offsets.begin(), offsets.end(), utf16End) - offsets.begin());
        auto [map, lineLevels] = order(a, b);
        std::vector<BidiRun> result;
        for (int i = a; i < b;) {
            int first = map[i], last = first;
            int level = lineLevels[first];
            bool rtl = level & 1;
            ++i;
            while (i < b && lineLevels[map[i]] == level && map[i] == last + (rtl ? -1 : 1)) last = map[i++];
            result.push_back({offsets[std::min(first, last)], offsets[std::max(first, last) + 1], rtl});
        }
        return result;
    }

    Json analysis() const {
        auto [map, lineLevels] = order(0, types.size());
        Json resolved = Json::array(), visual = Json::array();
        for (size_t p = 0; p < levels.size(); ++p) {
            for (int i = offsets[p]; i < offsets[p + 1]; ++i) resolved.push_back(int(lineLevels[p]));
        }
        for (int p : map) for (int i = offsets[p]; i < offsets[p + 1]; ++i) visual.push_back(i);
        return {{"levels", resolved}, {"order", visual}, {"baseLevel", baseLevel()}};
    }
};
