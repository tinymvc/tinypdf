import Foundation
import PDFKit

let url = URL(fileURLWithPath: CommandLine.arguments[1])
guard let document = PDFDocument(url: url) else {
    fatalError("Cannot open generated PDF")
}
let pages = (0..<document.pageCount).map { document.page(at: $0)!.string ?? "" }
// Opening a page directly exercises PDFKit's font cache initialization too.
let individualPages = (0..<document.pageCount).map { index in
    let fresh = PDFDocument(url: url)!
    return withExtendedLifetime(fresh) { fresh.page(at: index)!.string ?? "" }
}
var searches: [String: Double] = [:]
for phrase in ["বাংলা ভাষায় নির্ভুল নথি তৈরি", "اردو زبان میں خوش آمدید"] {
    let matches = document.findString(phrase, withOptions: [])
    searches[phrase] = matches.map { selection in
        selection.pages.reduce(0.0) { $0 + selection.bounds(for: $1).width }
    }.max() ?? 0
}
let result: [String: Any] = ["pages": pages, "individualPages": individualPages, "searches": searches]
FileHandle.standardOutput.write(try JSONSerialization.data(withJSONObject: result))
