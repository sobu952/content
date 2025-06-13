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
</Relationships>';
file_put_contents($temp_dir . '/word/_rels/document.xml.rels', $doc_rels);

// Utwórz zawartość dokumentu
$document_content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>';

foreach ($contents as $content) {
    $url = htmlspecialchars($content['url']);
    $text = $content['verified_text'] ?: $content['generated_text'];
    
    // Dodaj URL jako nagłówek
    $document_content .= '
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Heading1"/>
            </w:pPr>
            <w:r>
                <w:rPr>
                    <w:b/>
                    <w:sz w:val="24"/>
                </w:rPr>
                <w:t>' . $url . '</w:t>
            </w:r>
        </w:p>';
    
    // Parsuj HTML i konwertuj na format Word
    $text = preg_replace('/<h2[^>]*>(.*?)<\/h2>/i', '</w:t></w:r></w:p><w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="20"/></w:rPr><w:t>$1</w:t></w:r></w:p><w:p><w:r><w:t>', $text);
    $text = preg_replace('/<p[^>]*>(.*?)<\/p>/i', '$1</w:t></w:r></w:p><w:p><w:r><w:t>', $text);
    $text = preg_replace('/<strong[^>]*>(.*?)<\/strong>/i', '</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>$1</w:t></w:r><w:r><w:t>', $text);
    $text = preg_replace('/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/i', '</w:t></w:r><w:hyperlink r:id="rId1"><w:r><w:rPr><w:color w:val="0000FF"/><w:u w:val="single"/></w:rPr><w:t>$2</w:t></w:r></w:hyperlink><w:r><w:t>', $text);
    
    // Usuń pozostałe tagi HTML
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    $document_content .= '
        <w:p>
            <w:r>
                <w:t>' . htmlspecialchars($text) . '</w:t>
            </w:r>
        </w:p>';
    
    // Dodaj separację między elementami
    $document_content .= '
        <w:p>
            <w:r>
                <w:t></w:t>
            </w:r>
        </w:p>';
}

$document_content .= '
    </w:body>
</w:document>';

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