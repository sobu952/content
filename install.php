<?php
session_start();

// Sprawdź czy aplikacja jest już zainstalowana
if (file_exists('config.php')) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_POST) {
    $db_host = $_POST['db_host'];
    $db_name = $_POST['db_name'];
    $db_user = $_POST['db_user'];
    $db_pass = $_POST['db_pass'];
    $admin_email = $_POST['admin_email'];
    $admin_password = $_POST['admin_password'];
    
    try {
        // Połącz z bazą danych
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Utwórz tabele
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'user') DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS content_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            fields JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS prompts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            content_type_id INT,
            type ENUM('generate', 'verify') NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            content_type_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            strictness_level DECIMAL(2,1) DEFAULT 0.0,
            task_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (content_type_id) REFERENCES content_types(id)
        );
        
        CREATE TABLE IF NOT EXISTS task_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            url VARCHAR(500) NOT NULL,
            input_data JSON,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS generated_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_item_id INT NOT NULL,
            generated_text TEXT,
            verified_text TEXT,
            status ENUM('generated', 'verified', 'failed') DEFAULT 'generated',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_item_id) REFERENCES task_items(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS task_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_item_id INT NOT NULL,
            priority INT DEFAULT 1,
            attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            FOREIGN KEY (task_item_id) REFERENCES task_items(id) ON DELETE CASCADE
        );
        ";
        
        $pdo->exec($sql);
        
        // Dodaj domyślny typ treści
        $stmt = $pdo->prepare("INSERT INTO content_types (name, fields) VALUES (?, ?)");
        $default_fields = json_encode([
            'url' => ['type' => 'url', 'label' => 'Adres URL', 'required' => true],
            'keywords' => ['type' => 'textarea', 'label' => 'Frazy SEO', 'required' => true],
            'headings' => ['type' => 'text', 'label' => 'Nagłówki H2 (opcjonalnie)', 'required' => false],
            'characters' => ['type' => 'number', 'label' => 'Przybliżona liczba znaków', 'required' => true],
            'lead' => ['type' => 'checkbox', 'label' => 'Zacząć od leadu', 'required' => false],
            'internal_linking' => ['type' => 'text', 'label' => 'Linkowanie wewnętrzne (opcjonalnie)', 'required' => false]
        ]);
        $stmt->execute(['Opisy kategorii e-commerce', $default_fields]);
        $content_type_id = $pdo->lastInsertId();
        
        // Dodaj domyślne prompty
        $generate_prompt = 'Generate a high-quality, SEO-optimized Polish-language category description for an e-commerce website, formatted exclusively in pure HTML, following all instructions strictly.
ABSOLUTELY NO MARKDOWN. Use <strong> for bolding, NOT asterisks.
++DATA INPUT++
URL: {url}
SEO Keywords: {keywords}
H2 Headings: {headings}
Approximate Characters: {characters}
Lead Required: {lead}
Internal Linking: {internal_linking}
Strictness Level: {strictness_level}
++GENERAL INSTRUCTIONS++
The entire output MUST be in pure HTML. DO NOT use Markdown, code blocks, or any unsupported formatting. No asterisks, no # for headings.
Use only the following HTML tags: <h2>, <p>, <strong>, <a>.
DO NOT include <html>, <head>, <title>, <body>, or <br> tags.
DO NOT start the article with the title.
If Lead Required = TAK, begin with a single short introduction paragraph (<p>) of 50–80 words. Only one paragraph allowed.
If Lead Required = NIE, start directly with the first <h2> section.
++LENGTH INSTRUCTIONS++
The target total text length is {characters} characters.
Stay strictly within this limit.
For every 1000 characters, include exactly 1 <h2> section, followed by exactly 2 <p> paragraphs. No more, no less.
Each paragraph must contain several full sentences of varied length and structure.
Approximate word count per 1000 characters is 150 words. Use this proportion as guidance when composing each section.
Each <h2> section must address a unique aspect of the category and provide valuable, sales-oriented content relevant to e-commerce.
If necessary, prioritize brevity, concise phrasing, and eliminate redundant filler sentences to fit within the character limit.
++HEADINGS INSTRUCTIONS++
The H2 Headings value is: {headings}.
If the value is "GENERATE H2s", you must create natural, sales-oriented, SEO-optimized H2 subheadings in Polish, phrased as questions, highly relevant to the category and products.
If the value is a list of H2 subheadings, use them exactly as provided.
Absolutely no heading should repeat or closely duplicate another.
Limit the presence of SEO Keywords: {keywords} inside H2 headings to maximum 1 keyword occurrence per heading.
Do not attempt to include multiple keywords inside one H2 heading.
Never bold any part of H2 headings — <strong> tags are strictly forbidden inside H2.
++SEO KEYWORDS INSTRUCTIONS++
Use all keywords from SEO Keywords: {keywords} according to Strictness Level {strictness_level}:
Integrate keywords 2–3 times across the entire text.
Use the base form at least once for each keyword.
At Strictness {strictness_level}, allow partially flexible insertion of keywords, incorporating proper Polish grammatical inflections, acceptable synonyms or paraphrases when natural.
Balance SEO optimization with natural, fluent sentence integration, ensuring each occurrence fits grammatically and semantically into complete Polish sentences.
Never isolate keywords unnaturally or insert them without logical connection to the sentence meaning.
Ensure natural reading flow while preserving SEO value.
Distribute keyword occurrences smoothly across all sections. Avoid keyword clustering within one part of the text.
++INTERNAL LINKING INSTRUCTIONS++
The Internal Linking value is: {internal_linking}.
If the field is empty, skip this instruction.
If the field contains value, it will be provided in format: phrase - URL.
Automatically insert the provided phrase once inside the text as an internal link using standard HTML anchor format: <a href="URL">phrase</a>.
Integrate this internal link naturally into the flow of the text.
++BOLDING INSTRUCTIONS++
Use <strong> tags to bold entire occurrences of SEO Keywords: {keywords} as they appear in text, including grammatical inflections and flexible variations according to Strictness Level {strictness_level}.
Additionally, in each <h2> section (underneath the heading), apply <strong> tags to 1–2 important, meaningful sentences or phrases that enhance sales and engagement.
Do not bold partial phrases; always bold full expressions.
Do not duplicate <strong> tags on the same word.
NEVER place <strong> tags inside <h2> tags.
++STYLE INSTRUCTIONS++
Write in Polish using a natural, fluent, human-like tone, respecting Strictness Level {strictness_level}.
Use a neutral, professional, but friendly style suitable for e-commerce category descriptions.
Avoid first-person voice entirely.
Do not address the reader directly. Do not use forms such as: „Ty", „Wy", „Państwo", „Wasz", „Twój", „Wam", „Tobie" etc.
Avoid conversational openings like: „Zapewne szukacie…", „Jeśli szukasz…", „Wybieracie Państwo…".
Do not use any rhetorical questions addressing the reader directly.
Focus entirely on describing the category and its features objectively, naturally and fluently.
Avoid generic filler phrases such as: „w dzisiejszych czasach", "w świecie", „w tym artykule", „jak wspomniano wcześniej", „podsumowując" or similar.
Use varied sentence lengths and structures ensuring smooth reading flow, fully respecting the Sentence Structure Instructions.
++SENTENCE STRUCTURE INSTRUCTIONS++
Write using natural, fluid Polish sentence structures.
Combine related ideas into longer, complex, and compound sentences when appropriate.
Avoid overly short, isolated, or repetitive simple sentences.
Use a variety of sentence lengths to ensure a smooth, professional reading flow.
Allow sentence lengths to vary naturally based on content, avoiding mechanical sentence breakdowns.
Sentences must sound as if they were written by a native Polish e-commerce copywriter.
Additionally:
Ensure sentences are complex and compound, reflecting a high level of fluency in written Polish.
Use engaging and persuasive language, tailored to the target customer persona and appropriate for the tone of an e-commerce platform.
Maintain semantic richness by incorporating relevant synonyms, phrases, and keyword variations (but avoid keyword stuffing).
Structure the text logically: begin with an introduction to the category, explain its purpose or benefits, and smoothly transition into more detailed content or product group descriptions.
Add natural internal linking opportunities by referring to related categories or complementary products, when applicable.
Use active voice where possible to make the description dynamic and reader-friendly.
Avoid artificial filler content; ensure all sentences serve a purpose (informative, persuasive, or navigational).
Include a clear call to action or encouragement to explore the products offered within the category.
++NO LISTS POLICY++
DO NOT generate any lists, bullet points, enumerations or ordered sequences.
DO NOT simulate lists using line breaks, hyphens, commas, semicolons or separate short sentences.
DO NOT use: „w dzisiejszych czasach", "w świecie", „w tym artykule", „jak wspomniano wcześniej", „podsumowując" or similar.
Write all content as continuous, flowing paragraphs of full prose only.
Avoid any enumeration of features, characteristics, benefits or comparisons.
++ADDITIONAL RULES++
Do not include any system messages, summaries, notes, or explanations before or after the HTML output.
Deliver the full output without truncation.
Never invent product names, features, data, numbers or facts.
Use realistic examples and plausible information matching Polish market context.
Absolutely no FAQ, tables, lists, conclusions, or extra sections.
++FORMATTING REMINDER++
Only <h2>, <p>, <strong> and <a> tags allowed.
All headings: only first word capitalized, except proper nouns.
Use no Markdown, code blocks or non-HTML formatting.
Output must be pure HTML only.';

        $verify_prompt = 'Verify and correct the provided category description according to the following instructions:

Correct only grammar, spelling, punctuation, syntax and natural language flow.
Fix missing spaces inside words (e.g. "hantlamicharakteryzują" → "hantlami charakteryzują").
Keep exactly the same structure of headings (<h2>) and paragraphs (<p>).
Do not change or remove any SEO keywords — preserve all their occurrences.
Do not modify headings.
If any secondary keyword appears much less frequently than the primary one, balance their usage so that the frequency difference does not exceed 1.
If any additional keyword occurs more than 3 times, reduce it to 2–3 occurrences.
Do not rewrite or "humanize" — apply only technical linguistic correction.
Preserve existing HTML structure, including all <h2>, <p>, <strong>, <a> tags exactly as provided.
Do not add any lists, bullets, or modify the content structure.
Do not truncate or delete any sentences or paragraphs.
Output clean HTML, no markdown. Replace ** characters with proper <strong> tags if any.
Begin with the intro of the article, <p>.
Omit head section and H1.
Cut out all the newline characters on the output: \n, \n\n.
Verify if the content is proper, clean HTML with no markdown. If it is, pass it as-is. If not, correct and pass further.
Do not truncate any part of the input.

INPUT:
{generated_text}
++role++
Jesteś ekspertem lingwistycznym specjalizującym się w weryfikacji i korekcie językowej opisów kategorii e-commerce generowanych przez AI. Twoim zadaniem jest precyzyjna korekta językowa, eliminacja błędów składniowych i kontrola poprawności użycia fraz SEO bez zmiany treści i struktury tekstu.

++task++
Przejrzyj i popraw dostarczony opis kategorii. Skup się na korekcie błędów językowych, składniowych, interpunkcyjnych oraz na poprawności użycia fraz kluczowych. Zachowaj oryginalną strukturę i merytorykę tekstu. ZADBAJ O TO ABY KONIECZNIE PO KAŻDYM ZNACZNIKU </STRONG> BYŁA SPACJA, WYJĄTKIEM JEST MOMENT, GDY PO <STRONG> JEST JAKIŚ ZNAK INTERPUNKCYJNY.

++goal++
Dostarcz poprawiony, poprawny językowo i SEO-owo tekst kategorii w e-commerce, gotowy do publikacji. Nie zmieniaj narracji ani nie humanizuj tekstu.

++rules++
Poprawiaj:

Błędy gramatyczne, interpunkcyjne i składniowe.
Błędy wynikające z brakujących spacji w słowach.
Nienaturalny szyk zdań i płynność językową.
Sztuczne lub niezręczne konstrukcje AI.
Kontroluj:

Liczbę wystąpień fraz dodatkowych – redukuj do maksymalnie 2–3, jeśli przekraczają tę liczbę.
Liczbę wystąpień podwójnych fraz głównych – wyrównaj, aby różnica nie przekraczała 1.
Zachowaj dokładne formy i odmiany fraz SEO.
Nie usuwaj żadnych fraz SEO obecnych w treści.
Absolutnie NIE:

NIE zmieniaj treści nagłówków (<h2>).
NIE zmieniaj kolejności akapitów.
NIE dodawaj nowych zdań, informacji ani danych.
NIE stosuj humanizacji, stylizacji sprzedażowej ani adaptacji UX.
NIE wprowadzaj list wypunktowanych, wypunktowań, nagłówków dodatkowych.
NIE stosuj strony biernej tam, gdzie nie była użyta.
NIE usuwaj ani nie dokładaj żadnych elementów tekstu.
++step-by-step instructions++

Dokładnie przeanalizuj tekst pod kątem błędów językowych.
Usuń błędy wynikające z błędnych sklejeń słów.
Skoryguj nienaturalne szyki zdań i sztuczne konstrukcje AI.
Sprawdź i popraw interpunkcję według zasad języka polskiego.
Skontroluj liczbę wystąpień fraz kluczowych, popraw je według zasad.
Zachowaj całą strukturę HTML tekstu.
Absolutnie nie zmieniaj merytoryki, logiki i kolejności treści.
Nie skracaj ani nie wydłużaj tekstu.
++output format++
Podaj wyłącznie finalny, poprawiony tekst w czystym HTML. Nie dodawaj komentarzy, opisów, notatek. Gotowy kod HTML bez znaczników head/body/title.
Usuń wszystkie znaki nowej linii (\n, \n\n).';
        
        $stmt = $pdo->prepare("INSERT INTO prompts (content_type_id, type, content) VALUES (?, ?, ?)");
        $stmt->execute([$content_type_id, 'generate', $generate_prompt]);
        $stmt->execute([$content_type_id, 'verify', $verify_prompt]);
        
        // Utwórz użytkownika admin
        $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'admin')");
        $stmt->execute([$admin_email, $hashed_password]);
        
        // Utwórz plik konfiguracyjny
        $config_content = "<?php
define('DB_HOST', '$db_host');
define('DB_NAME', '$db_name');
define('DB_USER', '$db_user');
define('DB_PASS', '$db_pass');

function getDbConnection() {
    try {
        \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8', DB_USER, DB_PASS);
        \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return \$pdo;
    } catch(PDOException \$e) {
        die('Błąd połączenia z bazą danych: ' . \$e->getMessage());
    }
}
?>";
        
        file_put_contents('config.php', $config_content);
        
        $success = 'Instalacja zakończona pomyślnie! Możesz się teraz zalogować.';
        
    } catch(Exception $e) {
        $error = 'Błąd instalacji: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalacja - Generator treści SEO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card mt-5">
                    <div class="card-header">
                        <h3 class="mb-0">Instalacja Generatora treści SEO</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                            <a href="login.php" class="btn btn-primary">Przejdź do logowania</a>
                        <?php else: ?>
                        
                        <form method="POST">
                            <h5>Konfiguracja bazy danych</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="db_host" class="form-label">Host bazy danych</label>
                                        <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="db_name" class="form-label">Nazwa bazy danych</label>
                                        <input type="text" class="form-control" id="db_name" name="db_name" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="db_user" class="form-label">Użytkownik bazy danych</label>
                                        <input type="text" class="form-control" id="db_user" name="db_user" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="db_pass" class="form-label">Hasło bazy danych</label>
                                        <input type="password" class="form-control" id="db_pass" name="db_pass">
                                    </div>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <h5>Konto administratora</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="admin_email" class="form-label">Email administratora</label>
                                        <input type="email" class="form-control" id="admin_email" name="admin_email" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="admin_password" class="form-label">Hasło administratora</label>
                                        <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Zainstaluj</button>
                        </form>
                        
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>