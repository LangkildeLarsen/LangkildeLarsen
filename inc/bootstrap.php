<?php
date_default_timezone_set('Europe/Copenhagen');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

define('DATA_DIR', dirname(__DIR__) . '/data');
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');

function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function load_json(string $name, array $default = []): array
{
    $file = DATA_DIR . '/' . $name . '.json';
    if (!is_file($file)) {
        return $default;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function save_json(string $name, array $data): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    if (!is_file(DATA_DIR . '/.htaccess')) {
        file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
    }
    file_put_contents(
        DATA_DIR . '/' . $name . '.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function default_profile(): array
{
    return [
        'name' => 'Emil Langkilde Larsen',
        'title' => 'UI/UX- og grafisk designer · E-commerce · Marketing',
        'location' => 'Esbjerg, Danmark',
        'email' => 'emil@langkildelarsen.dk',
        'linkedin' => 'https://www.linkedin.com/in/langkildelarsen/',
        'dribbble' => 'https://dribbble.com/longkilos',
        'photo' => '',
        'about' => "Jeg er grafisk designer med speciale i UI/UX og har en iværksætters tilgang til design. I fem år byggede og drev jeg min egen webshop, Home Shoppen, som nåede en samlet omsætning på 15 mio. kr. – så jeg ved, hvad der skal til for at gøre en idé til en forretning.\n\nJeg er uddannet multimediedesigner og har arbejdet i Photoshop i over ti år. Jeg ser hvert projekt som en chance for at lære noget nyt og går til opgaverne både strategisk og kreativt.",
        'now' => 'Grafisk webdesigner hos EFUTURE i Esbjerg, hvor jeg arbejder med webshoppen, design, indhold og markedsføring af elcykler.',
        'experience' => [
            ['role' => 'Grafisk webdesigner', 'company' => 'EFUTURE', 'url' => 'https://efuture.dk', 'period' => 'dec. 2024 – nu', 'text' => 'Webshop, grafisk design, produktindhold og markedsføring. WordPress og e-handel.'],
            ['role' => 'Grafisk webdesigner, praktik', 'company' => 'Sikkerhedsgiganten', 'url' => 'https://www.sikkerhedsgiganten.dk', 'period' => 'jan. 2024 – mar. 2024', 'text' => 'Udvikling og lancering af en komplet ny Shopify Plus-hjemmeside.'],
            ['role' => 'Visuel identitet, projekt', 'company' => 'Esbjerg Museum', 'url' => 'https://esbjergmuseum.dk', 'period' => 'sep. 2023 – nov. 2023', 'text' => 'Komplet ny visuel identitet og branding. Projekt på Erhvervsakademi SydVest.'],
            ['role' => 'E-commerce-ansvarlig', 'company' => 'Vores Verden', 'url' => 'https://www.voresverden.dk', 'period' => 'sep. 2020 – maj 2022', 'text' => 'Etablering af webshop, content og procesflow samt kundeservice og butiksdrift.'],
            ['role' => 'Ejer', 'company' => 'Home Shoppen', 'url' => 'https://www.home-shoppen.dk', 'period' => 'nov. 2015 – jul. 2020', 'text' => "Etablerede webshoppen sammen med en partner og stod for branding, markedsføring, drift og professionalisering. Resultatet blev fordoblet tre år i træk, og shoppen aflønnede to fuldtidsmedarbejdere.\nSamlet omsætning på 15 mio. kr. over fire år og ca. 20.000 pakker sendt om året."],
            ['role' => 'Webdesigner, freelance', 'company' => 'AirNet', 'url' => 'https://www.airnet.dk', 'period' => 'jan. 2016 – mar. 2020', 'text' => 'Komplet ny visuel identitet og branding samt udvikling af hjemmeside.'],
            ['role' => 'Ejer', 'company' => 'Shopathome', 'period' => 'sep. 2016 – jun. 2019', 'text' => 'Visuel identitet, branding og udvikling af webshop.'],
            ['role' => 'Ejer', 'company' => 'blavand.dk', 'url' => 'https://www.blavand.dk', 'period' => 'jul. 2015 – jul. 2017', 'text' => 'Webcam, nyheder og information om Blåvand, finansieret via Google AdSense. Ca. 800.000 sidevisninger om året.'],
            ['role' => 'Marketing', 'company' => 'Inside Design House', 'period' => 'okt. 2015 – mar. 2016', 'text' => 'Sociale medier, grafisk design og markedsføring.'],
            ['role' => 'Studentermedhjælper', 'company' => 'Lammhults Biblioteksdesign', 'period' => '2012 – 2015', 'text' => 'Etablerede YouTube-kanalen LibraryPeople og producerede 100+ videoer, samt grafisk design og SEO.'],
            ['role' => 'Salgsmedarbejder', 'company' => 'Vores Verden', 'url' => 'https://www.voresverden.dk', 'period' => '2011 – mar. 2016', 'text' => 'Kasseekspedition, opfyldning og prismærkning.'],
        ],
        'projects' => [
            ['title' => 'Efuture', 'url' => 'https://dribbble.com/shots/25467502-Efuture', 'image' => '', 'show' => true],
            ['title' => 'BilligParfume', 'url' => 'https://dribbble.com/shots/24280992-BilligParfume', 'image' => '', 'show' => true],
            ['title' => 'Sikkerhedsgiganten.dk', 'url' => 'https://dribbble.com/shots/23587648-Sikkerhedsgiganten-dk', 'image' => '', 'show' => true],
            ['title' => 'Barkworthy Dog Treats', 'url' => 'https://dribbble.com/shots/23213887-Barkworthy-Dog-Treats', 'image' => '', 'show' => true],
            ['title' => 'Magazine Cover', 'url' => 'https://dribbble.com/shots/23195357-Magazine-Cover', 'image' => '', 'show' => true],
            ['title' => 'Esbjerg Museum', 'url' => 'https://dribbble.com/shots/23156150-Esbjerg-Museum', 'image' => '', 'show' => true],
            ['title' => 'Kanten Esbjerg', 'url' => 'https://dribbble.com/shots/21770978-Kanten-Esbjerg', 'image' => '', 'show' => true],
            ['title' => 'Radiant Festival', 'url' => 'https://dribbble.com/shots/21305984-Radiant-Festival', 'image' => '', 'show' => true],
            ['title' => 'Business Cards', 'url' => 'https://dribbble.com/shots/21255239-Business-Cards', 'image' => '', 'show' => true],
            ['title' => 'Lammhults', 'url' => 'https://dribbble.com/shots/21255161-Lammhults', 'image' => '', 'show' => true],
            ['title' => 'Home Shoppen', 'url' => 'https://dribbble.com/shots/21255035-Home-Shoppen', 'image' => '', 'show' => true],
            ['title' => 'Blåvand', 'url' => 'https://dribbble.com/shots/21254985-Bl-vand', 'image' => '', 'show' => true],
            ['title' => 'AirNet', 'url' => 'https://dribbble.com/shots/21254910-AirNet', 'image' => '', 'show' => true],
            ['title' => 'Shopathome', 'url' => 'https://dribbble.com/shots/21254826-Shopathome', 'image' => '', 'show' => true],
            ['title' => 'Longhorn BBQ DM', 'url' => 'https://dribbble.com/shots/21254806-Longhorn-BBQ-DM', 'image' => '', 'show' => true],
            ['title' => 'Stop Childlabour Redesign', 'url' => 'https://dribbble.com/shots/20812553-Stop-Childlabour-Redesign', 'image' => '', 'show' => true],
        ],
        'education' => [
            ['title' => 'Multimediedesigner', 'school' => 'Erhvervsakademi SydVest', 'period' => '2022 – 2024'],
            ['title' => 'Innovation', 'school' => 'Varde Handelsskole', 'period' => '2013 – 2016'],
            ['title' => 'Success Online', 'school' => 'Google', 'period' => 'Certificering'],
        ],
        'skills' => ['UI/UX', 'Grafisk design', 'E-handel', 'WordPress', 'Shopify', 'HTML & CSS', 'PHP', 'Photoshop', 'Branding & visuel identitet', 'SEO', 'Sociale medier', 'Markedsføring', 'Projektstyring'],
        'languages' => ['Dansk · modersmål', 'Engelsk · professionelt niveau'],
    ];
}

function get_profile(): array
{
    $def = default_profile();
    $p = array_merge($def, load_json('profile'));
    $urls = [];
    foreach ($def['experience'] as $x) {
        $urls[mb_strtolower($x['company'])] = $x['url'] ?? '';
    }
    foreach ($p['experience'] as &$x) {
        $x += ['url' => $urls[mb_strtolower($x['company'])] ?? '', 'logo' => ''];
    }
    unset($x);
    $logos = load_json('logos');
    foreach ($p['experience'] as &$x) {
        if ($x['logo'] === '' && $x['url'] !== '') {
            $x['logo'] = $logos[preg_replace('/^www\./', '', strtolower((string)parse_url($x['url'], PHP_URL_HOST)))] ?? '';
        }
    }
    unset($x);
    $images = load_json('project-images');
    foreach ($p['projects'] as &$pr) {
        $pr += ['image' => '', 'show' => true];
        if ($pr['image'] === '' && isset($images[$pr['url']])) {
            $pr['image'] = $images[$pr['url']];
        }
        if ($pr['image'] === '' && preg_match('~/shots/(\d+)~', $pr['url'], $m)) {
            $found = glob(dirname(__DIR__) . '/assets/projects/' . $m[1] . '.*');
            if ($found) {
                $pr['image'] = 'assets/projects/' . basename($found[0]);
            }
        }
    }
    unset($pr);
    return $p;
}

function img_src(string $path, string $prefix = ''): string
{
    return $prefix . (str_starts_with($path, 'assets/') ? $path : 'uploads/' . $path);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin']);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ugyldig anmodning. Gå tilbage og prøv igen.');
    }
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function dk_date(string $iso): string
{
    $months = ['jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];
    $t = strtotime($iso);
    if (!$t) {
        return '';
    }
    return date('j', $t) . '. ' . $months[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}

function linkify(string $text): string
{
    $html = nl2br(e($text));
    return preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $html);
}
