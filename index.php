<?php
declare(strict_types=1);

function isLoopbackAddress(string $address): bool
{
    $packedAddress = inet_pton($address);
    if ($packedAddress === false) {
        return false;
    }

    if (strlen($packedAddress) === 4) {
        return ord($packedAddress[0]) === 127;
    }

    return $packedAddress === inet_pton('::1');
}

function isLoopbackHost(string $hostHeader): bool
{
    $host = parse_url('http://' . $hostHeader, PHP_URL_HOST);
    return is_string($host) && in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
}

$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$hasProxyHeaders = false;
foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP'] as $proxyHeader) {
    if (!empty($_SERVER[$proxyHeader])) {
        $hasProxyHeaders = true;
        break;
    }
}

if (!isLoopbackAddress($remoteAddress) || !isLoopbackHost((string) ($_SERVER['HTTP_HOST'] ?? '')) || $hasProxyHeaders) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Accesso consentito solo dal computer locale.');
}

session_start([
    'use_strict_mode' => true,
    'use_only_cookies' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

$homeDirectory = getenv('HOME');
if ($homeDirectory === false || $homeDirectory === '') {
    throw new RuntimeException('Impossibile trovare la cartella personale in cui salvare i dati.');
}

$dataDirectory = $homeDirectory . '/.local/share/ricevute-prestazione';
if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0700, true) && !is_dir($dataDirectory)) {
    throw new RuntimeException('Impossibile creare la cartella protetta per i dati.');
}

$databasePath = $dataDirectory . '/ricevute.sqlite';
$db = new PDO('sqlite:' . $databasePath, options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA journal_mode = WAL');
$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    number_prefix TEXT NOT NULL DEFAULT 'R',
    withholding_rate REAL NOT NULL DEFAULT 20,
    inps_rate REAL NOT NULL DEFAULT 0,
    inps_worker_share_rate REAL NOT NULL DEFAULT 0,
    inps_threshold_cents INTEGER NOT NULL DEFAULT 500000,
    stamp_threshold_cents INTEGER NOT NULL DEFAULT 7747,
    stamp_duty_cents INTEGER NOT NULL DEFAULT 200,
    stamp_to_client INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    tax_code TEXT NOT NULL DEFAULT '',
    vat_number TEXT NOT NULL DEFAULT '',
    address TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    iban TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    tax_code TEXT NOT NULL DEFAULT '',
    vat_number TEXT NOT NULL DEFAULT '',
    address TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL,
    sequence INTEGER NOT NULL,
    number_prefix TEXT NOT NULL,
    receipt_date TEXT NOT NULL,
    provider_id INTEGER NOT NULL REFERENCES providers(id),
    client_id INTEGER NOT NULL REFERENCES clients(id),
    description TEXT NOT NULL,
    gross_cents INTEGER NOT NULL,
    withholding_rate REAL NOT NULL,
    withholding_cents INTEGER NOT NULL,
    inps_rate REAL NOT NULL,
    inps_worker_share_rate REAL NOT NULL,
    inps_threshold_cents INTEGER NOT NULL,
    inps_prior_income_cents INTEGER NOT NULL,
    inps_base_cents INTEGER NOT NULL,
    inps_total_cents INTEGER NOT NULL,
    inps_worker_cents INTEGER NOT NULL,
    stamp_threshold_cents INTEGER NOT NULL,
    stamp_duty_cents INTEGER NOT NULL,
    stamp_to_client INTEGER NOT NULL,
    total_due_cents INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (year, sequence)
);
INSERT OR IGNORE INTO settings (id) VALUES (1);
SQL);
chmod($databasePath, 0600);

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function euro(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

function inputMoney(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function csvSafe(string $value): string
{
    return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
}

function amountCents(string $value, string $label): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '.', $value);
    }
    if (!is_numeric($value) || (float) $value < 0 || (float) $value > 999999999) {
        throw new InvalidArgumentException("$label deve essere un importo valido e non negativo.");
    }
    return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
}

function rateValue(string $value, string $label): float
{
    if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
        throw new InvalidArgumentException("$label deve essere compresa tra 0 e 100.");
    }
    return round((float) $value, 4);
}

function postText(string $key, string $label, int $maxLength = 250): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '' || mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException("$label è obbligatorio e non può superare $maxLength caratteri.");
    }
    return $value;
}

function optionalText(string $key, int $maxLength = 250): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException("Un campo supera il limite di $maxLength caratteri.");
    }
    return $value;
}

function token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(token()) . '">';
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function showFlash(): void
{
    if (!isset($_SESSION['flash'])) {
        return;
    }
    $message = $_SESSION['flash'];
    unset($_SESSION['flash']);
    echo '<div class="notice notice-' . h($message['type']) . '" role="status">' . h($message['message']) . '</div>';
}

function receiptNumber(array $receipt): string
{
    return $receipt['number_prefix'] . '-' . $receipt['year'] . '-' . str_pad((string) $receipt['sequence'], 3, '0', STR_PAD_LEFT);
}

function dateItalian(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed ? $parsed->format('d/m/Y') : $date;
}

function inputValue(string $key, string $default = ''): string
{
    return h($_POST[$key] ?? $default);
}

function isChecked(string $key, bool $default = false): string
{
    $checked = array_key_exists($key, $_POST) ? $_POST[$key] === '1' : $default;
    return $checked ? ' checked' : '';
}

$settings = $db->query('SELECT * FROM settings WHERE id = 1')->fetch();
$page = (string) ($_GET['page'] ?? 'dashboard');
$allowedPages = ['dashboard', 'receipts', 'new', 'receipt', 'contacts', 'settings', 'export'];
if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'dashboard';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(token(), (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Richiesta non valida. Ricarica la pagina e riprova.');
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_provider') {
            $name = postText('name', 'Nome del prestatore');
            $taxCode = optionalText('tax_code', 50);
            $vatNumber = optionalText('vat_number', 50);
            $address = optionalText('address', 300);
            $email = optionalText('email', 150);
            $phone = optionalText('phone', 50);
            $iban = optionalText('iban', 50);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Inserisci un indirizzo email valido.');
            }
            $stmt = $db->prepare('INSERT INTO providers (name, tax_code, vat_number, address, email, phone, iban) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $taxCode, $vatNumber, $address, $email, $phone, $iban]);
            flash('success', 'Prestatore salvato.');
            redirect('?page=contacts');
        }

        if ($action === 'save_client') {
            $name = postText('name', 'Nome del committente');
            $taxCode = optionalText('tax_code', 50);
            $vatNumber = optionalText('vat_number', 50);
            $address = optionalText('address', 300);
            $email = optionalText('email', 150);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Inserisci un indirizzo email valido.');
            }
            $stmt = $db->prepare('INSERT INTO clients (name, tax_code, vat_number, address, email) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $taxCode, $vatNumber, $address, $email]);
            flash('success', 'Committente salvato.');
            redirect('?page=contacts');
        }

        if ($action === 'save_settings') {
            $prefix = strtoupper(postText('number_prefix', 'Prefisso', 12));
            if (!preg_match('/^[A-Z0-9_-]+$/', $prefix)) {
                throw new InvalidArgumentException('Il prefisso può contenere solo lettere, numeri, trattini e underscore.');
            }
            $withholding = rateValue((string) ($_POST['withholding_rate'] ?? ''), 'Ritenuta');
            $inpsRate = rateValue((string) ($_POST['inps_rate'] ?? ''), 'Aliquota INPS');
            $workerShare = rateValue((string) ($_POST['inps_worker_share_rate'] ?? ''), 'Quota INPS a carico del prestatore');
            $threshold = amountCents((string) ($_POST['inps_threshold'] ?? ''), 'Soglia INPS');
            $stampThreshold = amountCents((string) ($_POST['stamp_threshold'] ?? ''), 'Soglia bollo');
            $stampDuty = amountCents((string) ($_POST['stamp_duty'] ?? ''), 'Importo bollo');
            $stmt = $db->prepare('UPDATE settings SET number_prefix = ?, withholding_rate = ?, inps_rate = ?, inps_worker_share_rate = ?, inps_threshold_cents = ?, stamp_threshold_cents = ?, stamp_duty_cents = ?, stamp_to_client = ? WHERE id = 1');
            $stmt->execute([
                $prefix,
                $withholding,
                $inpsRate,
                $workerShare,
                $threshold,
                $stampThreshold,
                $stampDuty,
                isset($_POST['stamp_to_client']) ? 1 : 0,
            ]);
            flash('success', 'Impostazioni aggiornate. I documenti già registrati mantengono i valori originari.');
            redirect('?page=settings');
        }

        if ($action === 'save_receipt') {
            $providerId = filter_var($_POST['provider_id'] ?? null, FILTER_VALIDATE_INT);
            $clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$providerId || !$clientId) {
                throw new InvalidArgumentException('Seleziona un prestatore e un committente.');
            }
            $date = (string) ($_POST['receipt_date'] ?? '');
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Inserisci una data valida.');
            }
            $description = postText('description', 'Descrizione della prestazione', 1000);
            $gross = amountCents((string) ($_POST['gross'] ?? ''), 'Compenso lordo');
            if ($gross < 1) {
                throw new InvalidArgumentException('Il compenso lordo deve essere maggiore di zero.');
            }
            $priorIncome = amountCents((string) ($_POST['inps_prior_income'] ?? ''), 'Compensi già percepiti fuori dall’app');
            $providerCheck = $db->prepare('SELECT id FROM providers WHERE id = ?');
            $providerCheck->execute([$providerId]);
            $clientCheck = $db->prepare('SELECT id FROM clients WHERE id = ?');
            $clientCheck->execute([$clientId]);
            if (!$providerCheck->fetch() || !$clientCheck->fetch()) {
                throw new InvalidArgumentException('Il prestatore o il committente selezionato non esiste più.');
            }

            $year = (int) $parsedDate->format('Y');
            $db->exec('BEGIN IMMEDIATE TRANSACTION');
            try {
                $sequenceStmt = $db->prepare('SELECT COALESCE(MAX(sequence), 0) + 1 FROM receipts WHERE year = ?');
                $sequenceStmt->execute([$year]);
                $sequence = (int) $sequenceStmt->fetchColumn();

                $priorStmt = $db->prepare('SELECT COALESCE(SUM(gross_cents), 0) FROM receipts WHERE provider_id = ? AND year = ?');
                $priorStmt->execute([$providerId, $year]);
                $priorInApp = (int) $priorStmt->fetchColumn();
                $priorTotal = $priorIncome + $priorInApp;

                $withholdingRate = (float) $settings['withholding_rate'];
                $inpsRate = (float) $settings['inps_rate'];
                $workerShareRate = (float) $settings['inps_worker_share_rate'];
                $inpsThreshold = (int) $settings['inps_threshold_cents'];
                $inpsBase = max(0, $priorTotal + $gross - $inpsThreshold) - max(0, $priorTotal - $inpsThreshold);
                $withholding = (int) round($gross * $withholdingRate / 100, 0, PHP_ROUND_HALF_UP);
                $inpsTotal = (int) round($inpsBase * $inpsRate / 100, 0, PHP_ROUND_HALF_UP);
                $inpsWorker = (int) round($inpsTotal * $workerShareRate / 100, 0, PHP_ROUND_HALF_UP);
                $stampThreshold = (int) $settings['stamp_threshold_cents'];
                $stampDuty = $gross >= $stampThreshold ? (int) $settings['stamp_duty_cents'] : 0;
                $stampToClient = (int) $settings['stamp_to_client'];
                $totalDue = $gross - $withholding - $inpsWorker + ($stampToClient ? $stampDuty : 0);

                $insert = $db->prepare(<<<'SQL'
INSERT INTO receipts (
    year, sequence, number_prefix, receipt_date, provider_id, client_id, description,
    gross_cents, withholding_rate, withholding_cents, inps_rate, inps_worker_share_rate,
    inps_threshold_cents, inps_prior_income_cents, inps_base_cents, inps_total_cents,
    inps_worker_cents, stamp_threshold_cents, stamp_duty_cents, stamp_to_client, total_due_cents
) VALUES (
    :year, :sequence, :prefix, :date, :provider, :client, :description,
    :gross, :withholding_rate, :withholding, :inps_rate, :worker_rate,
    :threshold, :prior, :base, :inps_total, :worker, :stamp_threshold, :stamp, :stamp_to_client, :due
)
SQL);
                $insert->execute([
                    ':year' => $year,
                    ':sequence' => $sequence,
                    ':prefix' => $settings['number_prefix'],
                    ':date' => $date,
                    ':provider' => $providerId,
                    ':client' => $clientId,
                    ':description' => $description,
                    ':gross' => $gross,
                    ':withholding_rate' => $withholdingRate,
                    ':withholding' => $withholding,
                    ':inps_rate' => $inpsRate,
                    ':worker_rate' => $workerShareRate,
                    ':threshold' => $inpsThreshold,
                    ':prior' => $priorTotal,
                    ':base' => $inpsBase,
                    ':inps_total' => $inpsTotal,
                    ':worker' => $inpsWorker,
                    ':stamp_threshold' => $stampThreshold,
                    ':stamp' => $stampDuty,
                    ':stamp_to_client' => $stampToClient,
                    ':due' => $totalDue,
                ]);
                $receiptId = (int) $db->lastInsertId();
                $db->commit();
            } catch (PDOException $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            flash('success', 'Ricevuta ' . $settings['number_prefix'] . '-' . $year . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT) . ' registrata.');
            redirect('?page=receipt&id=' . $receiptId);
        }

        throw new InvalidArgumentException('Operazione non riconosciuta.');
    } catch (InvalidArgumentException $exception) {
        flash('error', $exception->getMessage());
        $returnPage = (string) ($_POST['return_page'] ?? 'dashboard');
        $returnPage = in_array($returnPage, ['new', 'contacts', 'settings'], true) ? $returnPage : 'dashboard';
        redirect('?page=' . $returnPage);
    }
}

if ($page === 'export') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ricevute-prestazione.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Impossibile generare il file di esportazione.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Numero', 'Data', 'Prestatore', 'Codice fiscale prestatore', 'Committente', 'Codice fiscale committente', 'Descrizione', 'Lordo', 'Ritenuta', 'INPS totale', 'INPS prestatore', 'Bollo', 'Netto da pagare'], ';');
    $exportRows = $db->query(<<<'SQL'
SELECT r.*, p.name AS provider_name, p.tax_code AS provider_tax_code,
       c.name AS client_name, c.tax_code AS client_tax_code
FROM receipts r
JOIN providers p ON p.id = r.provider_id
JOIN clients c ON c.id = r.client_id
ORDER BY r.receipt_date DESC, r.year DESC, r.sequence DESC
SQL)->fetchAll();
    foreach ($exportRows as $row) {
        fputcsv($output, [
            receiptNumber($row),
            $row['receipt_date'],
            csvSafe($row['provider_name']),
            csvSafe($row['provider_tax_code']),
            csvSafe($row['client_name']),
            csvSafe($row['client_tax_code']),
            csvSafe($row['description']),
            inputMoney((int) $row['gross_cents']),
            inputMoney((int) $row['withholding_cents']),
            inputMoney((int) $row['inps_total_cents']),
            inputMoney((int) $row['inps_worker_cents']),
            inputMoney((int) $row['stamp_duty_cents']),
            inputMoney((int) $row['total_due_cents']),
        ], ';');
    }
    fclose($output);
    exit;
}

$providers = $db->query('SELECT * FROM providers ORDER BY name COLLATE NOCASE')->fetchAll();
$clients = $db->query('SELECT * FROM clients ORDER BY name COLLATE NOCASE')->fetchAll();
$receiptRows = $db->query(<<<'SQL'
SELECT r.*, p.name AS provider_name, p.tax_code AS provider_tax_code,
       p.vat_number AS provider_vat_number, p.address AS provider_address,
       p.email AS provider_email, p.phone AS provider_phone, p.iban AS provider_iban,
       c.name AS client_name, c.tax_code AS client_tax_code,
       c.vat_number AS client_vat_number, c.address AS client_address, c.email AS client_email
FROM receipts r
JOIN providers p ON p.id = r.provider_id
JOIN clients c ON c.id = r.client_id
ORDER BY r.receipt_date DESC, r.year DESC, r.sequence DESC
SQL)->fetchAll();
$selectedReceipt = null;
if ($page === 'receipt') {
    $selectedId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    foreach ($receiptRows as $row) {
        if ((int) $row['id'] === $selectedId) {
            $selectedReceipt = $row;
            break;
        }
    }
    if ($selectedReceipt === null) {
        http_response_code(404);
        $page = 'receipts';
    }
}

$thisYear = (int) date('Y');
$summaryStmt = $db->prepare('SELECT COUNT(*) AS count, COALESCE(SUM(gross_cents), 0) AS gross, COALESCE(SUM(total_due_cents), 0) AS due FROM receipts WHERE year = ?');
$summaryStmt->execute([$thisYear]);
$summary = $summaryStmt->fetch();
$recentRows = array_slice($receiptRows, 0, 5);
$logo = rawurlencode('WhatsApp Image 2026-08-13 at 13.02.57.jpeg');
$isReceiptPage = $page === 'receipt' && $selectedReceipt !== null;
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f5f2">
    <title><?= $isReceiptPage ? 'Ricevuta ' . h(receiptNumber($selectedReceipt)) . ' · ' : '' ?>Ricevute occasionali</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="<?= $isReceiptPage ? 'receipt-view' : '' ?>">
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="?page=dashboard">
            <img src="<?= h($logo) ?>" alt="Vito D'Orio Web &amp; Software Solutions">
            <span>RICEVUTE<span class="brand-light"> · GESTIONE</span></span>
        </a>
        <nav aria-label="Navigazione principale">
            <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span>⌂</span> Panoramica</a>
            <a class="<?= in_array($page, ['receipts', 'receipt'], true) ? 'active' : '' ?>" href="?page=receipts"><span>▤</span> Ricevute</a>
            <a class="<?= $page === 'new' ? 'active' : '' ?>" href="?page=new"><span>＋</span> Nuova ricevuta</a>
            <a class="<?= $page === 'contacts' ? 'active' : '' ?>" href="?page=contacts"><span>♙</span> Anagrafiche</a>
            <a class="<?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings"><span>⚙</span> Impostazioni</a>
        </nav>
        <div class="sidebar-bottom">
            <span class="local-badge"><i></i> Dati solo su questo computer</span>
            <span class="sidebar-year"><?= $thisYear ?> · Registro locale</span>
        </div>
    </aside>

    <main class="main-content">
        <?php if (!$isReceiptPage): ?>
        <header class="topbar">
            <div class="breadcrumb">GESTIONE DOCUMENTI <span>/</span> <?= h(match ($page) {
                'dashboard' => 'PANORAMICA',
                'receipts' => 'RICEVUTE',
                'new' => 'NUOVA RICEVUTA',
                'contacts' => 'ANAGRAFICHE',
                'settings' => 'IMPOSTAZIONI',
                default => 'PANORAMICA',
            }) ?></div>
            <a class="button button-primary button-small" href="?page=new"><span>＋</span> Crea ricevuta</a>
        </header>
        <?php endif; ?>

        <?php if (!$isReceiptPage) showFlash(); ?>

        <?php if ($page === 'dashboard'): ?>
            <section class="page-heading">
                <div>
                    <p class="eyebrow">IL TUO LAVORO, IN ORDINE</p>
                    <h1>Panoramica</h1>
                    <p class="muted">Tieni sotto controllo le ricevute e i compensi dell’anno.</p>
                </div>
                <a class="button button-primary" href="?page=new">＋ Nuova ricevuta</a>
            </section>
            <section class="stat-grid">
                <article class="stat-card"><span class="stat-label">Ricevute nel <?= $thisYear ?></span><strong><?= (int) $summary['count'] ?></strong><span class="stat-foot">Documenti registrati</span></article>
                <article class="stat-card"><span class="stat-label">Compensi lordi</span><strong><?= h(euro((int) $summary['gross'])) ?></strong><span class="stat-foot">Totale dell’anno</span></article>
                <article class="stat-card stat-card-accent"><span class="stat-label">Netto da pagare registrato</span><strong><?= h(euro((int) $summary['due'])) ?></strong><span class="stat-foot">Totale dopo le trattenute</span></article>
            </section>
            <section class="panel">
                <div class="panel-heading">
                    <div><p class="eyebrow">ATTIVITÀ RECENTE</p><h2>Ultime ricevute</h2></div>
                    <a class="text-link" href="?page=receipts">Vedi archivio <span>→</span></a>
                </div>
                <?php if ($recentRows === []): ?>
                    <div class="empty-state"><span class="empty-icon">▤</span><h3>Il tuo archivio è pronto</h3><p>Inserisci il prestatore e il committente, poi crea la prima ricevuta.</p><a class="button button-primary" href="?page=contacts">Configura anagrafiche</a></div>
                <?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>Numero e data</th><th>Committente</th><th>Prestatore</th><th>Lordo</th><th>Netto</th><th></th></tr></thead><tbody>
                    <?php foreach ($recentRows as $row): ?>
                        <tr><td><a class="row-link" href="?page=receipt&amp;id=<?= (int) $row['id'] ?>"><?= h(receiptNumber($row)) ?></a><span class="table-sub"><?= h(dateItalian($row['receipt_date'])) ?></span></td><td><?= h($row['client_name']) ?></td><td><?= h($row['provider_name']) ?></td><td><?= h(euro((int) $row['gross_cents'])) ?></td><td class="amount-strong"><?= h(euro((int) $row['total_due_cents'])) ?></td><td><a class="icon-link" aria-label="Apri ricevuta" href="?page=receipt&amp;id=<?= (int) $row['id'] ?>">↗</a></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>
            <aside class="tip-card"><span class="tip-icon">i</span><p><strong>Nota sui calcoli</strong> Le aliquote e le soglie sono configurabili nelle impostazioni. Verifica i valori applicabili al tuo caso con il tuo commercialista: questa app è uno strumento organizzativo, non sostituisce una consulenza fiscale.</p></aside>

        <?php elseif ($page === 'receipts'): ?>
            <section class="page-heading">
                <div><p class="eyebrow">ARCHIVIO</p><h1>Le tue ricevute</h1><p class="muted">Tutti i documenti registrati restano consultabili e stampabili.</p></div>
                <div class="heading-actions"><a class="button button-secondary" href="?page=export">↓ Esporta CSV</a><a class="button button-primary" href="?page=new">＋ Nuova ricevuta</a></div>
            </section>
            <section class="panel">
                <?php if ($receiptRows === []): ?>
                    <div class="empty-state"><span class="empty-icon">▤</span><h3>Nessuna ricevuta ancora</h3><p>Quando registrerai una ricevuta, la ritroverai qui.</p><a class="button button-primary" href="?page=new">Crea la prima ricevuta</a></div>
                <?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>Numero</th><th>Data</th><th>Committente</th><th>Prestatore</th><th>Compenso lordo</th><th>Netto da pagare</th><th></th></tr></thead><tbody>
                    <?php foreach ($receiptRows as $row): ?>
                        <tr><td><a class="row-link" href="?page=receipt&amp;id=<?= (int) $row['id'] ?>"><?= h(receiptNumber($row)) ?></a></td><td><?= h(dateItalian($row['receipt_date'])) ?></td><td><?= h($row['client_name']) ?></td><td><?= h($row['provider_name']) ?></td><td><?= h(euro((int) $row['gross_cents'])) ?></td><td class="amount-strong"><?= h(euro((int) $row['total_due_cents'])) ?></td><td><a class="icon-link" aria-label="Apri ricevuta" href="?page=receipt&amp;id=<?= (int) $row['id'] ?>">↗</a></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>

        <?php elseif ($page === 'new'): ?>
            <section class="page-heading">
                <div><p class="eyebrow">NUOVO DOCUMENTO</p><h1>Crea una ricevuta</h1><p class="muted">Compila i dati della prestazione: il riepilogo viene calcolato con le impostazioni correnti.</p></div>
            </section>
            <?php if ($providers === [] || $clients === []): ?>
                <div class="notice notice-warning">Per creare una ricevuta aggiungi almeno un prestatore e un committente. <a class="text-link" href="?page=contacts">Vai alle anagrafiche →</a></div>
            <?php endif; ?>
            <form method="post" class="form-layout" id="receipt-form">
                <?= csrfField() ?><input type="hidden" name="action" value="save_receipt"><input type="hidden" name="return_page" value="new">
                <section class="panel form-panel">
                    <div class="form-section-title"><span class="step-number">01</span><div><h2>Le persone coinvolte</h2><p>Prestatore e committente della prestazione.</p></div></div>
                    <div class="form-grid">
                        <label class="field"><span>Prestatore <b>*</b></span><select name="provider_id" required><option value="">Seleziona prestatore</option><?php foreach ($providers as $provider): ?><option value="<?= (int) $provider['id'] ?>" <?= (string) $provider['id'] === (string) ($_POST['provider_id'] ?? '') ? 'selected' : '' ?>><?= h($provider['name']) ?><?= $provider['tax_code'] !== '' ? ' · ' . h($provider['tax_code']) : '' ?></option><?php endforeach; ?></select><?php if ($providers === []): ?><small><a href="?page=contacts">Aggiungi un prestatore</a></small><?php endif; ?></label>
                        <label class="field"><span>Committente <b>*</b></span><select name="client_id" required><option value="">Seleziona committente</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>" <?= (string) $client['id'] === (string) ($_POST['client_id'] ?? '') ? 'selected' : '' ?>><?= h($client['name']) ?><?= $client['tax_code'] !== '' ? ' · ' . h($client['tax_code']) : '' ?></option><?php endforeach; ?></select><?php if ($clients === []): ?><small><a href="?page=contacts">Aggiungi un committente</a></small><?php endif; ?></label>
                        <label class="field"><span>Data ricevuta <b>*</b></span><input name="receipt_date" type="date" value="<?= inputValue('receipt_date', date('Y-m-d')) ?>" required></label>
                        <label class="field"><span>Compenso lordo (€) <b>*</b></span><input name="gross" type="number" inputmode="decimal" min="0.01" step="0.01" placeholder="0,00" value="<?= inputValue('gross') ?>" required><small>Importo lordo pattuito prima delle trattenute.</small></label>
                        <label class="field field-wide"><span>Descrizione della prestazione <b>*</b></span><textarea name="description" rows="3" maxlength="1000" placeholder="Descrivi brevemente la prestazione svolta" required><?= inputValue('description') ?></textarea></label>
                    </div>
                </section>
                <section class="panel form-panel">
                    <div class="form-section-title"><span class="step-number">02</span><div><h2>Calcolo delle trattenute</h2><p>Aliquote e soglie vengono prese dalle impostazioni e salvate insieme a questa ricevuta.</p></div></div>
                    <div class="calculation-note"><span>i</span><p>Ritenuta configurata: <strong><?= h(number_format((float) $settings['withholding_rate'], 2, ',', '.')) ?>%</strong> · INPS: <strong><?= h(number_format((float) $settings['inps_rate'], 2, ',', '.')) ?>%</strong> sulla parte eccedente la soglia di <?= h(euro((int) $settings['inps_threshold_cents'])) ?>.</p></div>
                    <label class="field field-compact"><span>Compensi occasionali già percepiti quest’anno fuori da questa app (€)</span><input name="inps_prior_income" type="number" inputmode="decimal" min="0" step="0.01" value="<?= inputValue('inps_prior_income', '0.00') ?>"><small>Serve al calcolo dell’eventuale quota INPS oltre soglia. Inserisci 0 se non applicabile o se non sei sicuro: verifica il dato prima di emettere.</small></label>
                    <div class="calculation-preview" id="calculation-preview" data-withholding="<?= h($settings['withholding_rate']) ?>" data-inps="<?= h($settings['inps_rate']) ?>" data-worker="<?= h($settings['inps_worker_share_rate']) ?>" data-threshold="<?= h(inputMoney((int) $settings['inps_threshold_cents'])) ?>" data-stamp-threshold="<?= h(inputMoney((int) $settings['stamp_threshold_cents'])) ?>" data-stamp="<?= h(inputMoney((int) $settings['stamp_duty_cents'])) ?>" data-stamp-client="<?= (int) $settings['stamp_to_client'] ?>">
                        <div><span>Compenso lordo</span><strong id="preview-gross">—</strong></div><div><span>Ritenuta d’acconto</span><strong id="preview-withholding">—</strong></div><div><span>Quota INPS prestatore</span><strong id="preview-inps">—</strong></div><div><span>Imposta di bollo</span><strong id="preview-stamp">—</strong></div><div class="preview-total"><span>Netto indicativo</span><strong id="preview-net">—</strong></div>
                    </div>
                    <p class="form-disclaimer">Il calcolo in anteprima è indicativo: applicabilità, soglie e aliquote devono essere verificate per la tua situazione.</p>
                </section>
                <div class="form-actions"><a class="button button-secondary" href="?page=receipts">Annulla</a><button class="button button-primary" type="submit" <?= $providers === [] || $clients === [] ? 'disabled' : '' ?>>Registra e visualizza ricevuta <span>→</span></button></div>
            </form>

        <?php elseif ($page === 'receipt' && $selectedReceipt !== null): ?>
            <?php $r = $selectedReceipt; ?>
            <div class="receipt-toolbar">
                <a class="text-link" href="?page=receipts">← Torna all’archivio</a>
                <button class="button button-primary" type="button" onclick="window.print()">⤓ Stampa / Salva in PDF</button>
            </div>
            <?php showFlash(); ?>
            <article class="receipt-paper">
                <header class="receipt-header">
                    <div><img src="<?= h($logo) ?>" alt="Vito D'Orio Web &amp; Software Solutions"><p>RICEVUTA PER PRESTAZIONE OCCASIONALE</p></div>
                    <div class="receipt-number"><span>NUMERO</span><strong><?= h(receiptNumber($r)) ?></strong><span>DATA</span><strong><?= h(dateItalian($r['receipt_date'])) ?></strong></div>
                </header>
                <div class="receipt-parties">
                    <section><span class="receipt-label">PRESTATORE</span><h2><?= h($r['provider_name']) ?></h2><?php if ($r['provider_address'] !== ''): ?><p><?= nl2br(h($r['provider_address'])) ?></p><?php endif; ?><?php if ($r['provider_tax_code'] !== ''): ?><p><b>Codice fiscale:</b> <?= h($r['provider_tax_code']) ?></p><?php endif; ?><?php if ($r['provider_vat_number'] !== ''): ?><p><b>Partita IVA:</b> <?= h($r['provider_vat_number']) ?></p><?php endif; ?><?php if ($r['provider_email'] !== ''): ?><p><?= h($r['provider_email']) ?></p><?php endif; ?><?php if ($r['provider_phone'] !== ''): ?><p><?= h($r['provider_phone']) ?></p><?php endif; ?><?php if ($r['provider_iban'] !== ''): ?><p><b>IBAN:</b> <?= h($r['provider_iban']) ?></p><?php endif; ?></section>
                    <section><span class="receipt-label">COMMITTENTE</span><h2><?= h($r['client_name']) ?></h2><?php if ($r['client_address'] !== ''): ?><p><?= nl2br(h($r['client_address'])) ?></p><?php endif; ?><?php if ($r['client_tax_code'] !== ''): ?><p><b>Codice fiscale:</b> <?= h($r['client_tax_code']) ?></p><?php endif; ?><?php if ($r['client_vat_number'] !== ''): ?><p><b>Partita IVA:</b> <?= h($r['client_vat_number']) ?></p><?php endif; ?><?php if ($r['client_email'] !== ''): ?><p><?= h($r['client_email']) ?></p><?php endif; ?></section>
                </div>
                <div class="receipt-description"><span class="receipt-label">DESCRIZIONE DELLA PRESTAZIONE</span><p><?= nl2br(h($r['description'])) ?></p></div>
                <section class="receipt-calculations">
                    <div><span>Compenso lordo</span><strong><?= h(euro((int) $r['gross_cents'])) ?></strong></div>
                    <div><span>Ritenuta d’acconto (<?= h(number_format((float) $r['withholding_rate'], 2, ',', '.')) ?>%)</span><strong>− <?= h(euro((int) $r['withholding_cents'])) ?></strong></div>
                    <?php if ((int) $r['inps_total_cents'] > 0): ?><div><span>Contributo INPS calcolato (<?= h(number_format((float) $r['inps_rate'], 2, ',', '.')) ?>% su <?= h(euro((int) $r['inps_base_cents'])) ?>)</span><strong><?= h(euro((int) $r['inps_total_cents'])) ?></strong></div><div><span>Quota INPS trattenuta al prestatore (<?= h(number_format((float) $r['inps_worker_share_rate'], 2, ',', '.')) ?>%)</span><strong>− <?= h(euro((int) $r['inps_worker_cents'])) ?></strong></div><?php endif; ?>
                    <?php if ((int) $r['stamp_duty_cents'] > 0): ?><div><span>Imposta di bollo<?= (int) $r['stamp_to_client'] ? ' a carico del committente' : '' ?></span><strong><?= (int) $r['stamp_to_client'] ? '+ ' : '' ?><?= h(euro((int) $r['stamp_duty_cents'])) ?></strong></div><?php endif; ?>
                    <div class="receipt-total"><span>NETTO DA PAGARE</span><strong><?= h(euro((int) $r['total_due_cents'])) ?></strong></div>
                </section>
                <footer class="receipt-signature"><p>Luogo e data ______________________________</p><div><span>Firma del prestatore</span><i></i></div></footer>
                <div class="receipt-footer-note">Documento generato con archivio locale. Verificare i dati e gli adempimenti fiscali applicabili prima dell’utilizzo.</div>
            </article>

        <?php elseif ($page === 'contacts'): ?>
            <section class="page-heading"><div><p class="eyebrow">PERSONE E AZIENDE</p><h1>Anagrafiche</h1><p class="muted">Salva i dati ricorrenti per comporre più velocemente le ricevute.</p></div></section>
            <div class="contact-grid">
                <section class="panel">
                    <div class="panel-heading"><div><p class="eyebrow">CHI ESEGUE LA PRESTAZIONE</p><h2>Prestatore</h2></div><span class="count-pill"><?= count($providers) ?></span></div>
                    <form method="post" class="stack-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="save_provider"><input type="hidden" name="return_page" value="contacts">
                        <label class="field"><span>Nome e cognome / ragione sociale <b>*</b></span><input name="name" maxlength="250" value="<?= inputValue('name') ?>" required></label>
                        <div class="form-grid">
                            <label class="field"><span>Codice fiscale</span><input name="tax_code" maxlength="50" value="<?= inputValue('tax_code') ?>"></label>
                            <label class="field"><span>Partita IVA</span><input name="vat_number" maxlength="50" value="<?= inputValue('vat_number') ?>"></label>
                            <label class="field field-wide"><span>Indirizzo</span><input name="address" maxlength="300" value="<?= inputValue('address') ?>"></label>
                            <label class="field"><span>Email</span><input name="email" type="email" maxlength="150" value="<?= inputValue('email') ?>"></label>
                            <label class="field"><span>Telefono</span><input name="phone" maxlength="50" value="<?= inputValue('phone') ?>"></label>
                            <label class="field field-wide"><span>IBAN</span><input name="iban" maxlength="50" value="<?= inputValue('iban') ?>"></label>
                        </div>
                        <button class="button button-primary" type="submit">＋ Salva prestatore</button>
                    </form>
                    <div class="contact-list"><?php if ($providers === []): ?><p class="empty-inline">Non hai ancora aggiunto prestatori.</p><?php else: foreach ($providers as $provider): ?><div class="contact-item"><span class="avatar"><?= h(mb_strtoupper(mb_substr($provider['name'], 0, 1))) ?></span><div><strong><?= h($provider['name']) ?></strong><small><?= h($provider['tax_code'] ?: $provider['email'] ?: 'Dati fiscali non inseriti') ?></small></div></div><?php endforeach; endif; ?></div>
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><p class="eyebrow">CHI COMMISSIONA</p><h2>Committenti</h2></div><span class="count-pill"><?= count($clients) ?></span></div>
                    <form method="post" class="stack-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="save_client"><input type="hidden" name="return_page" value="contacts">
                        <label class="field"><span>Nome e cognome / ragione sociale <b>*</b></span><input name="name" maxlength="250" value="<?= inputValue('name') ?>" required></label>
                        <div class="form-grid">
                            <label class="field"><span>Codice fiscale</span><input name="tax_code" maxlength="50" value="<?= inputValue('tax_code') ?>"></label>
                            <label class="field"><span>Partita IVA</span><input name="vat_number" maxlength="50" value="<?= inputValue('vat_number') ?>"></label>
                            <label class="field field-wide"><span>Indirizzo</span><input name="address" maxlength="300" value="<?= inputValue('address') ?>"></label>
                            <label class="field field-wide"><span>Email</span><input name="email" type="email" maxlength="150" value="<?= inputValue('email') ?>"></label>
                        </div>
                        <button class="button button-primary" type="submit">＋ Salva committente</button>
                    </form>
                    <div class="contact-list"><?php if ($clients === []): ?><p class="empty-inline">Non hai ancora aggiunto committenti.</p><?php else: foreach ($clients as $client): ?><div class="contact-item"><span class="avatar avatar-blue"><?= h(mb_strtoupper(mb_substr($client['name'], 0, 1))) ?></span><div><strong><?= h($client['name']) ?></strong><small><?= h($client['tax_code'] ?: $client['email'] ?: 'Dati fiscali non inseriti') ?></small></div></div><?php endforeach; endif; ?></div>
                </section>
            </div>

        <?php elseif ($page === 'settings'): ?>
            <section class="page-heading"><div><p class="eyebrow">PREFERENZE E CALCOLI</p><h1>Impostazioni</h1><p class="muted">I valori modificati qui valgono solo per le nuove ricevute.</p></div></section>
            <form method="post" class="settings-layout">
                <?= csrfField() ?><input type="hidden" name="action" value="save_settings"><input type="hidden" name="return_page" value="settings">
                <section class="panel form-panel">
                    <div class="form-section-title"><span class="step-number">01</span><div><h2>Numerazione</h2><p>Il progressivo viene generato automaticamente per anno.</p></div></div>
                    <label class="field settings-field"><span>Prefisso</span><input name="number_prefix" maxlength="12" value="<?= inputValue('number_prefix', (string) $settings['number_prefix']) ?>" required><small>Esempio numero ricevuta: <?= h($settings['number_prefix']) ?>-<?= $thisYear ?>-001.</small></label>
                </section>
                <section class="panel form-panel">
                    <div class="form-section-title"><span class="step-number">02</span><div><h2>Parametri fiscali</h2><p>Imposta i valori da usare nei calcoli e nei documenti successivi.</p></div></div>
                    <div class="form-grid">
                        <label class="field"><span>Ritenuta d’acconto (%)</span><input name="withholding_rate" type="number" min="0" max="100" step="0.01" value="<?= inputValue('withholding_rate', (string) $settings['withholding_rate']) ?>" required><small>Aliquota da verificare in base alla posizione fiscale.</small></label>
                        <label class="field"><span>Aliquota INPS sulla base eccedente (%)</span><input name="inps_rate" type="number" min="0" max="100" step="0.01" value="<?= inputValue('inps_rate', (string) $settings['inps_rate']) ?>" required></label>
                        <label class="field"><span>Quota INPS trattenuta al prestatore (%)</span><input name="inps_worker_share_rate" type="number" min="0" max="100" step="0.01" value="<?= inputValue('inps_worker_share_rate', (string) $settings['inps_worker_share_rate']) ?>" required><small>Percentuale del contributo totale che riduce il netto da pagare.</small></label>
                        <label class="field"><span>Soglia annua INPS (€)</span><input name="inps_threshold" type="number" min="0" step="0.01" value="<?= inputValue('inps_threshold', inputMoney((int) $settings['inps_threshold_cents'])) ?>" required><small>Per la soglia si sommano i compensi già registrati e quelli inseriti nella ricevuta.</small></label>
                        <label class="field"><span>Soglia imposta di bollo (€)</span><input name="stamp_threshold" type="number" min="0" step="0.01" value="<?= inputValue('stamp_threshold', inputMoney((int) $settings['stamp_threshold_cents'])) ?>" required></label>
                        <label class="field"><span>Importo bollo (€)</span><input name="stamp_duty" type="number" min="0" step="0.01" value="<?= inputValue('stamp_duty', inputMoney((int) $settings['stamp_duty_cents'])) ?>" required></label>
                    </div>
                    <label class="check-field"><input type="checkbox" name="stamp_to_client" value="1"<?= isChecked('stamp_to_client', (bool) $settings['stamp_to_client']) ?>><span><strong>Aggiungi il bollo al netto da pagare</strong><small>Se non selezionato, il bollo è indicato in ricevuta ma non sommato all’importo dovuto.</small></span></label>
                </section>
                <aside class="tip-card"><span class="tip-icon">!</span><p><strong>Attenzione</strong> I valori iniziali sono solo impostazioni di esempio e non costituiscono indicazione fiscale. Aliquote, soglie, applicabilità del bollo e ripartizione dei contributi possono dipendere dalla tua situazione e dalla normativa vigente. Verificali con un professionista prima di emettere documenti.</p></aside>
                <div class="form-actions"><button class="button button-primary" type="submit">Salva impostazioni</button></div>
            </form>
        <?php endif; ?>
    </main>
</div>
<script src="assets/app.js" defer></script>
</body>
</html>
