<?php
require_once 'auth_check.php';

if (!isset($_GET['task_id']) || !is_numeric($_GET['task_id'])) {
    header('Location: tasks.php');
    exit;
}

$task_id = intval($_GET['task_id']);
$pdo = getDbConnection();

// Sprawdź czy zadanie należy do użytkownika
$stmt = $pdo->prepare("
    SELECT t.name as task_name, p.name as project_name
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.id = ? AND p.user_id = ?
");
$stmt->execute([$task_id, $_SESSION['user_id']]);
$task = $stmt->fetch();

if (!$task) {
    header('Location: tasks.php');
    exit;
}

// Pobierz wygenerowane treści
$stmt = $pdo->prepare("
    SELECT ti.url, gc.verified_text, gc.generated_text
    FROM task_items ti
    JOIN generated_content gc ON ti.id = gc.task_item_id
    WHERE ti.task_id = ? AND ti.status = 'completed'
    ORDER BY ti.id
");
$stmt->execute([$task_id]);
$contents = $stmt->fetchAll();

if (empty($contents)) {
    header('Location: task_details.php?id=' . $task_id . '&error=no_content');
    exit;
}

// Utwórz dokument DOCX
$filename = 'content_' . $task_id . '_' . date('Y-m-d_H-i-s') . '.docx';

// Rozpocznij buforowanie wyjścia
ob_start();

// Nagłówki dla pliku DOCX
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Utwórz tymczasowy katalog
$temp_dir = sys_get_temp_dir() . '/docx_' . uniqid();
mkdir($temp_dir);

// Utwórz strukturę katalogów DOCX
mkdir($temp_dir . '/_rels');
mkdir($temp_dir . '/word');
mkdir($temp_dir . '/word/_rels');

// Plik [Content_Types].xml
$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>';
file_put_contents($temp_dir . '/[Content_Types].xml', $content_types);

// Plik _rels/.rels
$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
file_put_contents($temp_dir . '/_rels/.rels', $rels);

// Plik word/_rels/document.xml.rels
$doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
file_put_contents($temp_dir . '/word/_rels/document.xml.rels', $doc_rels);

// Plik styles.xml z definicjami stylów
$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:docDefaults>
        <w:rPrDefault>
            <w:rPr>
                <w:rFonts w:ascii="Calibri" w:eastAsia="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>
                <w:sz w:val="22"/>
                <w:szCs w:val="22"/>
                <w:lang w:val="pl-PL" w:eastAsia="en-US" w:bidi="ar-SA"/>
            </w:rPr>
        </w:rPrDefault>
        <w:pPrDefault>
            <w:pPr>
                <w:spacing w:after="200" w:line="276" w:lineRule="auto"/>
            </w:pPr>
        </w:pPrDefault>
    </w:docDefaults>
    <w:style w:type="paragraph" w:styleId="Normal">
        <w:name w:val="Normal"/>
        <w:qFormat/>
        <w:pPr>
            <w:spacing w:after="200" w:line="276" w:lineRule="auto"/>
        </w:pPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading1">
        <w:name w:val="heading 1"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:link w:val="Heading1Char"/>
        <w:uiPriority w:val="9"/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:keepLines/>
            <w:spacing w:before="480" w:after="0"/>
            <w:outlineLvl w:val="0"/>
        </w:pPr>
        <w:rPr>
            <w:rFonts w:asciiTheme="majorHAnsi" w:eastAsiaTheme="majorEastAsia" w:hAnsiTheme="majorHAnsi" w:cstheme="majorBidi"/>
            <w:b/>
            <w:bCs/>
            <w:color w:val="2F5496" w:themeColor="accent1" w:themeShade="BF"/>
            <w:sz w:val="32"/>
            <w:szCs w:val="32"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading2">
        <w:name w:val="heading 2"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:link w:val="Heading2Char"/>
        <w:uiPriority w:val="9"/>
        <w:unhideWhenUsed/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:keepLines/>
            <w:spacing w:before="200" w:after="0"/>
            <w:outlineLvl w:val="1"/>
        </w:pPr>
        <w:rPr>
            <w:rFonts w:asciiTheme="majorHAnsi" w:eastAsiaTheme="majorEastAsia" w:hAnsiTheme="majorHAnsi" w:cstheme="majorBidi"/>
            <w:b/>
            <w:bCs/>
            <w:color w:val="2F5496" w:themeColor="accent1" w:themeShade="BF"/>
            <w:sz w:val="26"/>
            <w:szCs w:val="26"/>
        </w:rPr>
    </w:style>
    <w:style w:type="character" w:styleId="Heading1Char">
        <w:name w:val="Heading 1 Char"/>
        <w:basedOn w:val="DefaultParagraphFont"/>
        <w:link w:val="Heading1"/>
        <w:uiPriority w:val="9"/>
        <w:rPr>
            <w:rFonts w:asciiTheme="majorHAnsi" w:eastAsiaTheme="majorEastAsia" w:hAnsiTheme="majorHAnsi" w:cstheme="majorBidi"/>
            <w:b/>
            <w:bCs/>
            <w:color w:val="2F5496" w:themeColor="accent1" w:themeShade="BF"/>
            <w:sz w:val="32"/>
            <w:szCs w:val="32"/>
        </w:rPr>
    </w:style>
    <w:style w:type="character" w:styleId="Heading2Char">
        <w:name w:val="Heading 2 Char"/>
        <w:basedOn w:val="DefaultParagraphFont"/>
        <w:link w:val="Heading2"/>
        <w:uiPriority w:val="9"/>
        <w:rPr>
            <w:rFonts w:asciiTheme="majorHAnsi" w:eastAsiaTheme="majorEastAsia" w:hAnsiTheme="majorHAnsi" w:cstheme="majorBidi"/>
            <w:b/>
            <w:bCs/>
            <w:color w:val="2F5496" w:themeColor="accent1" w:themeShade="BF"/>
            <w:sz w:val="26"/>
            <w:szCs w:val="26"/>
        </w:rPr>
    </w:style>
    <w:style w:type="character" w:styleId="DefaultParagraphFont">
        <w:name w:val="Default Paragraph Font"/>
        <w:uiPriority w:val="1"/>
        <w:semiHidden/>
        <w:unhideWhenUsed/>
    </w:style>
</w:styles>';
file_put_contents($temp_dir . '/word/styles.xml', $styles);

// Funkcja do konwersji HTML na Word XML
function htmlToWordXml($html) {
    // Usuń zbędne białe znaki i znaki nowej linii
    $html = preg_replace('/\s+/', ' ', $html);
    $html = trim($html);
    
    $xml = '';
    
    // Podziel HTML na elementy
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    
    foreach ($dom->childNodes as $node) {
        $xml .= processNode($node);
    }
    
    return $xml;
}

function processNode($node) {
    $xml = '';
    
    switch ($node->nodeName) {
        case 'h2':
            $text = htmlspecialchars($node->textContent, ENT_XML1, 'UTF-8');
            $xml .= '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>' . $text . '</w:t></w:r></w:p>';
            break;
            
        case 'p':
            $xml .= '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr>';
            $xml .= processInlineContent($node);
            $xml .= '</w:p>';
            break;
            
        case '#text':
            if (trim($node->textContent)) {
                $text = htmlspecialchars(trim($node->textContent), ENT_XML1, 'UTF-8');
                $xml .= '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr><w:r><w:t>' . $text . '</w:t></w:r></w:p>';
            }
            break;
            
        default:
            // Dla innych elementów, przetwórz dzieci
            foreach ($node->childNodes as $child) {
                $xml .= processNode($child);
            }
            break;
    }
    
    return $xml;
}

function processInlineContent($node) {
    $xml = '';
    
    foreach ($node->childNodes as $child) {
        switch ($child->nodeName) {
            case '#text':
                $text = htmlspecialchars($child->textContent, ENT_XML1, 'UTF-8');
                $xml .= '<w:r><w:t>' . $text . '</w:t></w:r>';
                break;
                
            case 'strong':
            case 'b':
                $text = htmlspecialchars($child->textContent, ENT_XML1, 'UTF-8');
                $xml .= '<w:r><w:rPr><w:b/></w:rPr><w:t>' . $text . '</w:t></w:r>';
                break;
                
            case 'a':
                $text = htmlspecialchars($child->textContent, ENT_XML1, 'UTF-8');
                $href = $child->getAttribute('href');
                // Dla uproszczenia, renderuj linki jako zwykły tekst z adresem URL
                $xml .= '<w:r><w:rPr><w:color w:val="0000FF"/><w:u w:val="single"/></w:rPr><w:t>' . $text . '</w:t></w:r>';
                if ($href && $href !== $text) {
                    $xml .= '<w:r><w:t> (' . htmlspecialchars($href, ENT_XML1, 'UTF-8') . ')</w:t></w:r>';
                }
                break;
                
            default:
                // Dla innych elementów inline, przetwórz rekurencyjnie
                $xml .= processInlineContent($child);
                break;
        }
    }
    
    return $xml;
}

// Utwórz zawartość dokumentu
$document_content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <w:body>';

foreach ($contents as $content) {
    $url = htmlspecialchars($content['url'], ENT_XML1, 'UTF-8');
    $text = $content['verified_text'] ?: $content['generated_text'];
    
    // Dodaj URL jako nagłówek 1
    $document_content .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>' . $url . '</w:t></w:r></w:p>';
    
    // Konwertuj HTML na Word XML
    $document_content .= htmlToWordXml($text);
    
    // Dodaj separację między elementami
    $document_content .= '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr><w:r><w:t></w:t></w:r></w:p>';
}

$document_content .= '</w:body></w:document>';

file_put_contents($temp_dir . '/word/document.xml', $document_content);

// Utwórz archiwum ZIP
$zip = new ZipArchive();
$zip_filename = $temp_dir . '.zip';

if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
    die('Cannot create ZIP file');
}

// Dodaj pliki do archiwum
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($temp_dir),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $name => $file) {
    if (!$file->isDir()) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($temp_dir) + 1);
        $zip->addFile($filePath, $relativePath);
    }
}

$zip->close();

// Wyślij plik
readfile($zip_filename);

// Usuń tymczasowe pliki
function deleteDirectory($dir) {
    if (!file_exists($dir)) return;
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

deleteDirectory($temp_dir);
unlink($zip_filename);

// Zakończ buforowanie
ob_end_flush();
?>