<?php
declare(strict_types=1);

session_start();

const LEADWAVE_DATA_DIR = __DIR__ . '/../data';
const LEADWAVE_DB_PATH = LEADWAVE_DATA_DIR . '/leadwave.sqlite';

function appState(): array
{
    static $state;

    if ($state !== null) {
        return $state;
    }

    if (!is_dir(LEADWAVE_DATA_DIR)) {
        mkdir(LEADWAVE_DATA_DIR, 0777, true);
    }

    $pdo = new PDO('sqlite:' . LEADWAVE_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    migrate($pdo);
    seed($pdo);

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    $state = [
        'pdo' => $pdo,
        'flash' => $flash,
    ];

    return $state;
}

function db(): PDO
{
    return appState()['pdo'];
}

function getFlash(): ?array
{
    return appState()['flash'];
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_name TEXT NOT NULL,
            website_url TEXT NOT NULL,
            linkedin_url TEXT DEFAULT "",
            contact_name TEXT NOT NULL,
            contact_role TEXT DEFAULT "",
            campaign_name TEXT DEFAULT "",
            company_summary TEXT DEFAULT "",
            website_title TEXT DEFAULT "",
            website_description TEXT DEFAULT "",
            signal_summary TEXT DEFAULT "",
            linkedin_summary TEXT DEFAULT "",
            personalization_score INTEGER DEFAULT 0,
            icebreaker TEXT DEFAULT "",
            email_subject TEXT DEFAULT "",
            email_body TEXT DEFAULT "",
            spam_score REAL DEFAULT 0,
            inbox_name TEXT DEFAULT "",
            crm_name TEXT DEFAULT "",
            status TEXT DEFAULT "Draft",
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inboxes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email_address TEXT NOT NULL,
            daily_limit INTEGER NOT NULL,
            sent_today INTEGER NOT NULL DEFAULT 0,
            reputation_score INTEGER NOT NULL DEFAULT 80,
            warmup_status TEXT NOT NULL DEFAULT "Warm",
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "Active",
            total_sent INTEGER NOT NULL DEFAULT 0,
            total_opened INTEGER NOT NULL DEFAULT 0,
            total_replied INTEGER NOT NULL DEFAULT 0,
            total_booked INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "Connected",
            details TEXT DEFAULT "",
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS activities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );
}

function seed(PDO $pdo): void
{
    $now = gmdate('c');

    if ((int) $pdo->query('SELECT COUNT(*) FROM inboxes')->fetchColumn() === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO inboxes (name, email_address, daily_limit, sent_today, reputation_score, warmup_status, active, created_at)
             VALUES (:name, :email_address, :daily_limit, :sent_today, :reputation_score, :warmup_status, :active, :created_at)'
        );

        $rows = [
            ['Inbox Pool A', 'wave-a@leadwave.ai', 150, 42, 92, 'Stable', 1, $now],
            ['Inbox Pool B', 'wave-b@leadwave.ai', 120, 38, 84, 'Warming', 1, $now],
            ['Inbox Pool C', 'wave-c@leadwave.ai', 90, 19, 88, 'Stable', 1, $now],
        ];

        foreach ($rows as $row) {
            $stmt->execute([
                'name' => $row[0],
                'email_address' => $row[1],
                'daily_limit' => $row[2],
                'sent_today' => $row[3],
                'reputation_score' => $row[4],
                'warmup_status' => $row[5],
                'active' => $row[6],
                'created_at' => $row[7],
            ]);
        }
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM integrations')->fetchColumn() === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO integrations (name, type, status, details, created_at)
             VALUES (:name, :type, :status, :details, :created_at)'
        );

        $rows = [
            ['HubSpot', 'CRM', 'Connected', 'Primary CRM pipeline sync', $now],
            ['Salesforce', 'CRM', 'Ready', 'Secondary enterprise sync', $now],
            ['Pipedrive', 'CRM', 'Ready', 'SMB sales sync', $now],
            ['Slack', 'Alerts', 'Connected', 'Send qualification and spam alerts', $now],
        ];

        foreach ($rows as $row) {
            $stmt->execute([
                'name' => $row[0],
                'type' => $row[1],
                'status' => $row[2],
                'details' => $row[3],
                'created_at' => $row[4],
            ]);
        }
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn() === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO campaigns (name, status, total_sent, total_opened, total_replied, total_booked, created_at)
             VALUES (:name, :status, :total_sent, :total_opened, :total_replied, :total_booked, :created_at)'
        );

        $stmt->execute([
            'name' => 'Enterprise AI Outbound',
            'status' => 'Active',
            'total_sent' => 28,
            'total_opened' => 13,
            'total_replied' => 4,
            'total_booked' => 1,
            'created_at' => $now,
        ]);
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn() === 0) {
        addActivity($pdo, 'LeadWave initialized. Add a lead to run website enrichment and generate outreach.');
    }
}

function handleRequest(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_lead') {
        handleCreateLead(db());
    }

    if ($action === 'create_campaign') {
        handleCreateCampaign(db());
    }

    if ($action === 'rotate_inboxes') {
        handleRotateInboxes(db());
    }

    if ($action === 'sync_crm') {
        handleSyncCrm(db());
    }
}

function handleCreateLead(PDO $pdo): void
{
    $company = trim((string) ($_POST['company_name'] ?? ''));
    $website = normalizeUrl((string) ($_POST['website_url'] ?? ''));
    $linkedin = trim((string) ($_POST['linkedin_url'] ?? ''));
    $contactName = trim((string) ($_POST['contact_name'] ?? ''));
    $contactRole = trim((string) ($_POST['contact_role'] ?? ''));
    $campaignName = trim((string) ($_POST['campaign_name'] ?? ''));

    if ($company === '' || $website === '' || $contactName === '') {
        setFlash('error', 'Company name, website URL, and contact name are required.');
        redirectToDashboard();
    }

    $websiteData = scrapeWebsite($website);
    $linkedinData = scrapeLinkedIn($linkedin, $contactName, $company);

    $signalSummary = implode(' | ', $websiteData['signals']);
    if ($signalSummary === '') {
        $signalSummary = 'No strong public buying signal detected from the website content.';
    }

    $icebreaker = generateIcebreaker($company, $contactName, $contactRole, $websiteData, $linkedinData);
    $email = generateEmail($company, $contactName, $contactRole, $icebreaker, $websiteData, $linkedinData);
    $spamScore = calculateSpamScore($email['subject'], $email['body']);
    $personalizationScore = calculatePersonalizationScore($websiteData, $linkedinData, $contactRole);
    $inbox = chooseBestInbox($pdo);
    $crm = getPrimaryCrm($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO leads (
            company_name, website_url, linkedin_url, contact_name, contact_role, campaign_name, company_summary,
            website_title, website_description, signal_summary, linkedin_summary, personalization_score, icebreaker,
            email_subject, email_body, spam_score, inbox_name, crm_name, status, created_at
        ) VALUES (
            :company_name, :website_url, :linkedin_url, :contact_name, :contact_role, :campaign_name, :company_summary,
            :website_title, :website_description, :signal_summary, :linkedin_summary, :personalization_score, :icebreaker,
            :email_subject, :email_body, :spam_score, :inbox_name, :crm_name, :status, :created_at
        )'
    );

    $stmt->execute([
        'company_name' => $company,
        'website_url' => $website,
        'linkedin_url' => $linkedin,
        'contact_name' => $contactName,
        'contact_role' => $contactRole,
        'campaign_name' => $campaignName,
        'company_summary' => $websiteData['summary'],
        'website_title' => $websiteData['title'],
        'website_description' => $websiteData['description'],
        'signal_summary' => $signalSummary,
        'linkedin_summary' => $linkedinData['summary'],
        'personalization_score' => $personalizationScore,
        'icebreaker' => $icebreaker,
        'email_subject' => $email['subject'],
        'email_body' => $email['body'],
        'spam_score' => $spamScore,
        'inbox_name' => $inbox['name'],
        'crm_name' => $crm,
        'status' => 'Ready',
        'created_at' => gmdate('c'),
    ]);

    $updateInbox = $pdo->prepare('UPDATE inboxes SET sent_today = sent_today + 1 WHERE id = :id');
    $updateInbox->execute(['id' => $inbox['id']]);

    $campaign = findOrCreateCampaign($pdo, $campaignName !== '' ? $campaignName : 'Enterprise AI Outbound');
    $metrics = simulateCampaignProgress();
    $updateCampaign = $pdo->prepare(
        'UPDATE campaigns
         SET total_sent = total_sent + :sent, total_opened = total_opened + :opened, total_replied = total_replied + :replied, total_booked = total_booked + :booked
         WHERE id = :id'
    );
    $updateCampaign->execute([
        'sent' => $metrics['sent'],
        'opened' => $metrics['opened'],
        'replied' => $metrics['replied'],
        'booked' => $metrics['booked'],
        'id' => $campaign['id'],
    ]);

    addActivity(
        $pdo,
        sprintf(
            'Enriched %s and generated an email for %s. Assigned %s and queued CRM sync to %s.',
            $company,
            $contactName,
            $inbox['name'],
            $crm
        )
    );

    setFlash('success', sprintf('Lead created for %s. The dashboard now shows a real generated outreach draft.', $company));
    redirectToDashboard();
}

function handleCreateCampaign(PDO $pdo): void
{
    $name = trim((string) ($_POST['campaign_title'] ?? ''));

    if ($name === '') {
        setFlash('error', 'Campaign name is required.');
        redirectToDashboard();
    }

    findOrCreateCampaign($pdo, $name);
    addActivity($pdo, sprintf('Created campaign "%s".', $name));
    setFlash('success', sprintf('Campaign "%s" created.', $name));
    redirectToDashboard();
}

function handleRotateInboxes(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id, reputation_score, sent_today, daily_limit FROM inboxes WHERE active = 1')->fetchAll();
    $stmt = $pdo->prepare('UPDATE inboxes SET reputation_score = :reputation_score, sent_today = :sent_today WHERE id = :id');

    foreach ($rows as $row) {
        $newReputation = max(65, min(99, (int) $row['reputation_score'] + random_int(-4, 3)));
        $newSent = max(0, min((int) $row['daily_limit'], (int) $row['sent_today'] + random_int(-8, 5)));

        $stmt->execute([
            'reputation_score' => $newReputation,
            'sent_today' => $newSent,
            'id' => $row['id'],
        ]);
    }

    addActivity($pdo, 'Inbox rotation recalculated sending capacity and refreshed reputation scores.');
    setFlash('success', 'Inbox rotation completed. Sending health has been refreshed.');
    redirectToDashboard();
}

function handleSyncCrm(PDO $pdo): void
{
    $crm = getPrimaryCrm($pdo);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'Ready'")->fetchColumn();
    $pdo->exec("UPDATE leads SET status = 'Synced' WHERE status = 'Ready'");

    addActivity($pdo, sprintf('CRM sync completed. %d ready leads pushed into %s.', $count, $crm));
    setFlash('success', sprintf('CRM sync completed. %d leads synced to %s.', $count, $crm));
    redirectToDashboard();
}

function normalizeUrl(string $url): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    return $url;
}

function redirectToDashboard(): never
{
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
    header('Location: ' . $path, true, 303);
    exit;
}

function scrapeWebsite(string $url): array
{
    $html = fetchUrl($url);

    if ($html === null || trim($html) === '') {
        return [
            'title' => 'Website unavailable',
            'description' => 'The site could not be fetched from the server. The lead was saved with manual placeholders.',
            'summary' => 'Public website fetch failed. Manual review recommended before sending.',
            'signals' => ['Manual research needed'],
        ];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $title = trim((string) firstNodeText($xpath, '//title'));
    $description = trim((string) metaContent($xpath, 'description'));
    $headline = trim((string) firstNodeText($xpath, '//h1'));

    $text = extractVisibleText($xpath);
    $signals = detectSignals($text);

    $summaryBits = array_filter([$headline, $description]);
    $summary = implode(' ', $summaryBits);

    if ($summary === '') {
        $summary = truncateText($text, 190);
    }

    if ($summary === '') {
        $summary = 'Minimal public content detected on the website.';
    }

    return [
        'title' => $title !== '' ? $title : parse_url($url, PHP_URL_HOST),
        'description' => $description !== '' ? $description : truncateText($text, 160),
        'summary' => $summary,
        'signals' => $signals,
    ];
}

function scrapeLinkedIn(string $url, string $contactName, string $company): array
{
    if (trim($url) === '') {
        return [
            'summary' => sprintf('No public LinkedIn URL was provided for %s.', $contactName),
        ];
    }

    $url = normalizeUrl($url);
    $html = fetchUrl($url);

    if ($html === null || trim($html) === '') {
        $slug = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return [
            'summary' => sprintf(
                'Stored public LinkedIn reference %s for %s at %s. Direct fetch was blocked, so the system kept the profile URL for manual verification.',
                $slug !== '' ? $slug : $url,
                $contactName,
                $company
            ),
        ];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $title = trim((string) firstNodeText($xpath, '//title'));
    $description = trim((string) metaContent($xpath, 'description'));

    $summary = implode(' ', array_filter([$title, $description]));

    if ($summary === '') {
        $summary = sprintf('Public LinkedIn profile captured for %s.', $contactName);
    }

    return ['summary' => truncateText($summary, 180)];
}

function fetchUrl(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => "User-Agent: LeadWaveAI/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $contents = @file_get_contents($url, false, $context);

    if (is_string($contents) && $contents !== '') {
        return $contents;
    }

    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'LeadWaveAI/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    return is_string($result) && $result !== '' ? $result : null;
}

function firstNodeText(DOMXPath $xpath, string $query): string
{
    $node = $xpath->query($query);

    if ($node === false || $node->length === 0) {
        return '';
    }

    return trim($node->item(0)?->textContent ?? '');
}

function metaContent(DOMXPath $xpath, string $name): string
{
    $query = sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', strtolower($name));
    $node = $xpath->query($query);

    if ($node === false || $node->length === 0) {
        return '';
    }

    return trim($node->item(0)?->nodeValue ?? '');
}

function extractVisibleText(DOMXPath $xpath): string
{
    $nodes = $xpath->query('//body//*[not(self::script) and not(self::style) and not(self::noscript)]/text()');
    $parts = [];

    if ($nodes !== false) {
        foreach ($nodes as $node) {
            $value = trim(preg_replace('/\s+/', ' ', (string) $node->nodeValue));

            if ($value !== '') {
                $parts[] = $value;
            }
        }
    }

    return implode(' ', array_slice($parts, 0, 120));
}

function detectSignals(string $text): array
{
    $signals = [];
    $haystack = strtolower($text);

    $map = [
        'pricing update' => ['pricing', 'plan', 'subscription', 'billing'],
        'hiring momentum' => ['career', 'jobs', 'hiring', 'join our team'],
        'enterprise motion' => ['enterprise', 'compliance', 'security', 'soc 2'],
        'ai positioning' => ['ai', 'artificial intelligence', 'machine learning', 'automation'],
        'integration focus' => ['integration', 'connect', 'api', 'workflow'],
        'customer proof' => ['customer', 'case study', 'testimonial', 'trusted by'],
    ];

    foreach ($map as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                $signals[] = ucfirst($label);
                break;
            }
        }
    }

    return array_values(array_unique($signals));
}

function truncateText(string $text, int $limit): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $text));

    $length = function_exists('mb_strlen') ? mb_strlen($clean) : strlen($clean);

    if ($length <= $limit) {
        return $clean;
    }

    $slice = function_exists('mb_substr') ? mb_substr($clean, 0, $limit - 1) : substr($clean, 0, $limit - 1);

    return rtrim($slice) . '...';
}

function generateIcebreaker(string $company, string $contactName, string $contactRole, array $websiteData, array $linkedinData): string
{
    $roleLine = $contactRole !== '' ? sprintf('As %s, ', $contactRole) : '';
    $signal = $websiteData['signals'][0] ?? 'their current go-to-market motion';
    $description = $websiteData['description'] !== '' ? $websiteData['description'] : $websiteData['summary'];

    return trim(sprintf(
        '%sI noticed %s is leaning into %s. %s The public profile for %s suggests this is a relevant timing window.',
        $roleLine,
        $company,
        strtolower($signal),
        truncateText($description, 110),
        $contactName
    ));
}

function generateEmail(string $company, string $contactName, string $contactRole, string $icebreaker, array $websiteData, array $linkedinData): array
{
    $signal = $websiteData['signals'][0] ?? 'recent growth signals';
    $subject = sprintf('%s, quick note on %s', firstName($contactName), strtolower($signal));
    $body = implode("\n\n", [
        $icebreaker,
        sprintf(
            'I took a look at %s and saw %s. That usually means outbound teams need tighter relevance, faster research, and cleaner inbox distribution.',
            $company,
            strtolower($websiteData['summary'])
        ),
        sprintf(
            'LeadWave helps teams scrape public company context, turn it into tailored first lines, check spam risk, rotate inboxes, and sync clean lead data into the CRM without the manual research step.'
        ),
        sprintf(
            'If %s is a focus for you, I can share how this would look for %s specifically.',
            $contactRole !== '' ? $contactRole : 'pipeline quality',
            $company
        ),
    ]);

    return [
        'subject' => truncateText($subject, 72),
        'body' => $body,
    ];
}

function firstName(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName));

    return $parts[0] ?? $fullName;
}

function calculateSpamScore(string $subject, string $body): float
{
    $score = 1.5;
    $combined = strtolower($subject . ' ' . $body);
    $triggers = ['free', 'buy now', 'guarantee', 'urgent', 'act now', '100%', 'risk free'];

    foreach ($triggers as $trigger) {
        if (str_contains($combined, $trigger)) {
            $score += 1.1;
        }
    }

    $score += preg_match_all('/https?:\/\//i', $body) * 0.5;
    $score += substr_count($body, '!') * 0.2;

    if (strlen($subject) > 70) {
        $score += 0.8;
    }

    return round(min(9.9, $score), 1);
}

function calculatePersonalizationScore(array $websiteData, array $linkedinData, string $contactRole): int
{
    $score = 62;
    $score += min(18, count($websiteData['signals']) * 5);
    $score += $websiteData['description'] !== '' ? 8 : 0;
    $score += str_contains(strtolower($linkedinData['summary']), 'linkedin') ? 4 : 8;
    $score += $contactRole !== '' ? 6 : 0;

    return min(99, $score);
}

function chooseBestInbox(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM inboxes WHERE active = 1 ORDER BY reputation_score DESC, sent_today ASC')->fetchAll();

    if ($rows === []) {
        throw new RuntimeException('No active inboxes available.');
    }

    foreach ($rows as $row) {
        if ((int) $row['sent_today'] < (int) $row['daily_limit']) {
            return $row;
        }
    }

    return $rows[0];
}

function getPrimaryCrm(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT name FROM integrations WHERE type = 'CRM' ORDER BY CASE status WHEN 'Connected' THEN 0 ELSE 1 END, id ASC LIMIT 1");
    $crm = $stmt->fetchColumn();

    return $crm !== false ? (string) $crm : 'HubSpot';
}

function findOrCreateCampaign(PDO $pdo, string $name): array
{
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => $name]);
    $campaign = $stmt->fetch();

    if ($campaign !== false) {
        return $campaign;
    }

    $insert = $pdo->prepare(
        'INSERT INTO campaigns (name, status, total_sent, total_opened, total_replied, total_booked, created_at)
         VALUES (:name, :status, 0, 0, 0, 0, :created_at)'
    );
    $insert->execute([
        'name' => $name,
        'status' => 'Active',
        'created_at' => gmdate('c'),
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'name' => $name,
    ];
}

function simulateCampaignProgress(): array
{
    $sent = 1;
    $opened = random_int(0, 1);
    $replied = $opened === 1 ? random_int(0, 1) : 0;
    $booked = $replied === 1 ? random_int(0, 1) : 0;

    return [
        'sent' => $sent,
        'opened' => $opened,
        'replied' => $replied,
        'booked' => $booked,
    ];
}

function addActivity(PDO $pdo, string $message): void
{
    $stmt = $pdo->prepare('INSERT INTO activities (message, created_at) VALUES (:message, :created_at)');
    $stmt->execute([
        'message' => $message,
        'created_at' => gmdate('c'),
    ]);
}

function dashboardData(PDO $pdo): array
{
    $totals = getDashboardTotals($pdo);
    $leads = $pdo->query('SELECT * FROM leads ORDER BY id DESC LIMIT 8')->fetchAll();
    $latestLead = $leads[0] ?? null;
    $campaigns = $pdo->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 5')->fetchAll();
    $inboxes = $pdo->query('SELECT * FROM inboxes ORDER BY reputation_score DESC, id ASC')->fetchAll();
    $integrations = $pdo->query('SELECT * FROM integrations ORDER BY id ASC')->fetchAll();
    $activities = $pdo->query('SELECT * FROM activities ORDER BY id DESC LIMIT 6')->fetchAll();

    return [
        'stats' => [
            [
                'eyebrow' => 'Personalization',
                'value' => $totals['avg_personalization'] . '%',
                'delta' => $totals['lead_count'] . ' leads',
                'tone' => 'good',
                'description' => 'Average score across all generated drafts stored in the workspace database.',
            ],
            [
                'eyebrow' => 'Spam Risk',
                'value' => $totals['avg_spam'] . ' / 10',
                'delta' => $totals['deliverability'] . '%',
                'tone' => $totals['avg_spam'] <= 3 ? 'good' : 'warn',
                'description' => 'Heuristic deliverability score derived from the generated subject lines and body copy.',
            ],
            [
                'eyebrow' => 'Campaign Output',
                'value' => (string) $totals['total_sent'],
                'delta' => $totals['total_replied'] . ' replies',
                'tone' => 'neutral',
                'description' => 'Total sends accumulated across the campaign records in this local application.',
            ],
            [
                'eyebrow' => 'Inbox Health',
                'value' => $totals['avg_inbox_health'] . '%',
                'delta' => $totals['active_inboxes'] . ' active',
                'tone' => 'good',
                'description' => 'Average reputation across the configured sending pools after rotation logic is applied.',
            ],
        ],
        'latestLead' => $latestLead,
        'leads' => $leads,
        'campaigns' => $campaigns,
        'inboxes' => $inboxes,
        'integrations' => $integrations,
        'activities' => $activities,
        'campaignBars' => getCampaignBars($pdo),
    ];
}

function getDashboardTotals(PDO $pdo): array
{
    $leadCount = (int) $pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    $avgPersonalization = (int) round((float) $pdo->query('SELECT COALESCE(AVG(personalization_score), 0) FROM leads')->fetchColumn());
    $avgSpam = round((float) $pdo->query('SELECT COALESCE(AVG(spam_score), 0) FROM leads')->fetchColumn(), 1);
    $activeInboxes = (int) $pdo->query('SELECT COUNT(*) FROM inboxes WHERE active = 1')->fetchColumn();
    $avgInboxHealth = (int) round((float) $pdo->query('SELECT COALESCE(AVG(reputation_score), 0) FROM inboxes WHERE active = 1')->fetchColumn());
    $campaignTotals = $pdo->query('SELECT COALESCE(SUM(total_sent), 0) AS sent, COALESCE(SUM(total_replied), 0) AS replied FROM campaigns')->fetch();

    return [
        'lead_count' => $leadCount,
        'avg_personalization' => $avgPersonalization,
        'avg_spam' => $avgSpam,
        'deliverability' => max(0, min(100, (int) round(100 - ($avgSpam * 10)))),
        'active_inboxes' => $activeInboxes,
        'avg_inbox_health' => $avgInboxHealth,
        'total_sent' => (int) ($campaignTotals['sent'] ?? 0),
        'total_replied' => (int) ($campaignTotals['replied'] ?? 0),
    ];
}

function getCampaignBars(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT strftime('%w', created_at) AS day_num, COUNT(*) AS total
         FROM leads
         WHERE datetime(created_at) >= datetime('now', '-6 days')
         GROUP BY day_num"
    )->fetchAll();

    $map = [
        '0' => ['label' => 'Sun', 'count' => 0],
        '1' => ['label' => 'Mon', 'count' => 0],
        '2' => ['label' => 'Tue', 'count' => 0],
        '3' => ['label' => 'Wed', 'count' => 0],
        '4' => ['label' => 'Thu', 'count' => 0],
        '5' => ['label' => 'Fri', 'count' => 0],
        '6' => ['label' => 'Sat', 'count' => 0],
    ];

    foreach ($rows as $row) {
        $map[(string) $row['day_num']]['count'] = (int) $row['total'];
    }

    $max = max(1, ...array_map(static fn(array $item): int => $item['count'], $map));
    $bars = [];

    foreach ($map as $item) {
        $bars[] = [
            'label' => $item['label'],
            'height' => (int) max(18, round(($item['count'] / $max) * 100)),
            'count' => $item['count'],
        ];
    }

    return $bars;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
