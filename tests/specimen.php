<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use Spark\Pdf\HtmlPdf;
$root=__DIR__ . '/fonts/';
$cases = [
'notosansbengali'=>['bn','বাংলা ভাষায় নির্ভুল নথি তৈরি'],
'notosansdevanagari'=>['hi','हिन्दी भाषा — क्ष त्र ज्ञ कि कर्म श्री'],
'notosanstamil'=>['ta','தமிழ் மொழி அனைவருக்கும் வணக்கம்'],
'notosanstelugu'=>['te','తెలుగు భాష ప్రపంచానికి నమస్కారం'],
'notosansmalayalam'=>['ml','മലയാളം ഭാഷ എല്ലാവർക്കും നമസ്കാരം'],
'notosanskannada'=>['kn','ಕನ್ನಡ ಭಾಷೆ ಎಲ್ಲರಿಗೂ ನಮಸ್ಕಾರ'],
'notosansgujarati'=>['gu','ગુજરાતી ભાષા વિશ્વને નમસ્કાર'],
'notosansgurmukhi'=>['pa','ਪੰਜਾਬੀ ਭਾਸ਼ਾ ਸਭ ਨੂੰ ਸਤਿ ਸ੍ਰੀ ਅਕਾਲ'],
'notosanssinhala'=>['si','සිංහල භාෂාව ලෝකයට ආයුබෝවන්'],
'notosansoriya'=>['or','ଓଡ଼ିଆ ଭାଷା ସମସ୍ତଙ୍କୁ ନମସ୍କାର'],
'notonaskharabic'=>['ar','مَرْحَبًا بِالْعَالَمِ 2026 (PDF)'],
'notonastaliqurdu'=>['ur','اردو زبان میں خوش آمدید'],
'notosanshebrew'=>['he','שָׁלוֹם עוֹלָם 2026'],
'notosansthai'=>['th','ภาษาไทย ยินดีต้อนรับ สวัสดีชาวโลก'],
'notosanslao'=>['lo','ພາສາລາວ ສະບາຍດີ'],
'notosanskhmer'=>['km','ភាសាខ្មែរ សួស្តីពិភពលោក'],
'notosansmyanmar'=>['my','မြန်မာဘာသာ မင်္ဂလာပါ'],
'notoseriftibetan'=>['bo','བོད་ཡིག བཀྲ་ཤིས་བདེ་ལེགས།'],
'notosanssc'=>['zh-Hans','简体中文：你好，世界！'],
'notosanstc'=>['zh-Hant','繁體中文：你好，世界！'],
'notosansjp'=>['ja','日本語：こんにちは、世界。'],
'notosanskr'=>['ko','한국어: 안녕하세요 세계'],
'notosans'=>['und','Français café œuvre — Tiếng Việt: tiếng nói — Ελληνικά — Русский'],
'notocoloremoji'=>['und','😀 👨‍👩‍👧‍👦 🇧🇩 👍🏽 ❤️'],
];
$pdf=new HtmlPdf(margin:12, fontSize:12);
foreach($cases as $family=>$case) { $files=glob($root.$family.'/*.ttf'); $file=array_values(array_filter($files,fn($f)=>!str_contains($f,'Italic')))[0]; $pdf->addFont($family,$file); }
$pdf->setFallbackFonts(array_keys($cases));
$html='<h1 style="font-size:20pt">TinyPDF 1.1.0 — multilingual specimen</h1><table style="width:100%;border-collapse:collapse">';
foreach($cases as $family=>[$lang,$text]) {
 $dir=in_array($lang,['ar','ur','he'])?'rtl':'ltr';
 $html.='<tr><td style="width:65pt;font-size:9pt;padding:3pt">'.$lang.'</td><td lang="'.$lang.'" dir="'.$dir.'" style="font-family:'.$family.';font-size:14pt;padding:3pt">'.$text.'</td></tr>';
}
$html.='</table>';
$pdf->addHtml($html);
$pdf->addHtml('<h2>Mixed direction and styles</h2><p dir="rtl" lang="ar" style="font-family:notonaskharabic">مَرْحَبًا <span style="color:#006699">بِالْعَالَمِ</span> <bdi>Invoice #2026 (USD 100)</bdi> نهاية</p><p style="font-family:notosansbengali">ক<span style="color:red">্ষি</span> — প্রাক্তন শিক্ষার্থী — Hello বাংলা हिन्दी 中文</p><h2>Vertical Japanese</h2><div lang="ja" style="writing-mode:vertical-rl;text-orientation:mixed;font-family:notosansjp;height:240pt;width:160pt;font-size:18pt;border:1pt solid #999;padding:8pt">日本語の縦書き。TinyPDF 1.1.0 正確な文字配置。こんにちは、世界。</div><h2>After vertical box</h2><p>Horizontal text continues here.</p>');
$pdf->save($argv[1] ?? dirname(__DIR__) . '/output/pdf/tinypdf-1.1.0-multilingual.pdf');
echo strlen($pdf->output())," bytes\n";
