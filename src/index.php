<?php declare(strict_types = 1);

namespace DaveRandom\BadgeOfShame;

use Amp\Artax\Client as HttpClient;
use Amp\Artax\Request as HttpRequest;
use Amp\Artax\Response as HttpResponse;
use ExceptionalJSON\DecodeErrorException;

function return_empty_svg(string $error = null)
{
    if ($error !== null) {
        \trigger_error($error);
    }

    \header('Content-Type: image/svg+xml; charset=utf-8');
    exit('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>');
}

function return_badge_svg($name, $commitUrl)
{
    $name = \htmlspecialchars($name, ENT_COMPAT | ENT_XML1, 'UTF-8');
    $commitUrl = \htmlspecialchars($commitUrl, ENT_COMPAT | ENT_XML1, 'UTF-8');

    $nameWidth = \strlen($name) * 10;
    $nameBoxWidth = $nameWidth + 10;
    $faultBoxWidth = 43;
    $totalWidth = $nameBoxWidth + $faultBoxWidth;

    \header('Content-Type: image/svg+xml; charset=utf-8');
    exit('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' . $totalWidth . '" height="20">
    <linearGradient id="b" x2="0" y2="100%">
        <stop offset="0" stop-color="#bbb" stop-opacity=".1" />
        <stop offset="1" stop-opacity=".1" />
    </linearGradient>
    <clipPath id="a">
        <rect width="' . $totalWidth . '" height="20" rx="3" fill="#fff" />
    </clipPath>
    <g clip-path="url(#a)">
        <path fill="#555" d="M0 0h' . $nameBoxWidth . 'v20H0z" />
        <path fill="#e05d44" d="M' . $nameBoxWidth . ' 0h' . $faultBoxWidth . 'v20H' . $nameBoxWidth . 'z" />
        <path fill="url(#b)" d="M0 0h' . $totalWidth . 'v20H0z" />
    </g>
    <g fill="#fff" text-anchor="middle" font-family="DejaVu Sans,Verdana,Geneva,sans-serif" font-size="11">
        <a xlink:href="' . $commitUrl . '">
            <text x="' . ($nameBoxWidth / 2) . '" y="14" fill="#eee">' . $name . '\'s</text>
            <text x="' . (($faultBoxWidth / 2) + $nameBoxWidth) . '" y="14" fill="#eee">FAULT</text>
        </a>
    </g>
</svg>');
}

if (!\preg_match('#^/([^/]+/[^/]+)$#', $_SERVER['REQUEST_URI'], $match)) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$repoSlug = $match[1];
$apcKey = 'BADGE:' . $repoSlug;

$url = \sprintf('https://api.travis-ci.org/repos/%s', $repoSlug);

$httpClient = new HttpClient();

$request = (new HttpRequest)
    ->setUri($url)
    ->setHeader('User-Agent', 'Badge Of Shame v1.0')
    ->setHeader('Accept', 'application/vnd.travis-ci.2+json');

/** @var HttpResponse $response */
$response = \Amp\wait($httpClient->request($request));

if ($response->getStatus() !== 200) {
    return_empty_svg('Travis API request #1 returned ' . $response->getStatus());
}

try {
    $decoded = \ExceptionalJSON\decode((string)$response->getBody(), true);
} catch (DecodeErrorException $e) {
    return_empty_svg('Travis API request #1 returned invalid JSON');
}

if (!isset($decoded['repo']['last_build_state'], $decoded['repo']['last_build_id'])) {
    return_empty_svg('Travis API response #2 missing data');
}

$lastBuildId = $decoded['repo']['last_build_id'];

if (\apcu_exists($apcKey)) {
    $data = \apcu_fetch($apcKey);

    if ($data['last_build_id'] === $lastBuildId) {
        $data['last_build_success']
            ? return_empty_svg()
            : return_badge_svg($data['last_login'], $data['last_url']);
    }
}

if ($decoded['repo']['last_build_state'] === 'success') {
    \apcu_store($apcKey, [
        'last_build_id' => $decoded['repo']['last_build_id'],
        'last_build_success' => true,
    ]);
    return_empty_svg();
}

$url = \sprintf('https://api.travis-ci.org/repos/%s/builds', $repoSlug);

$request = (new HttpRequest)
    ->setUri($url)
    ->setHeader('User-Agent', 'Badge Of Shame v1.0')
    ->setHeader('Accept', 'application/vnd.travis-ci.2+json');

/** @var HttpResponse $response */
$response = \Amp\wait($httpClient->request($request));

if ($response->getStatus() !== 200) {
    return_empty_svg('Travis API request #2 returned ' . $response->getStatus());
}

try {
    $decoded = \ExceptionalJSON\decode((string)$response->getBody(), true);
} catch (DecodeErrorException $e) {
    return_empty_svg('Travis API request #2 returned invalid JSON');
}

if (!isset($decoded['builds'], $decoded['commits'])) {
    return_empty_svg('Travis API response #2 missing data');
}

$commitId = $commitSha = null;

foreach ($decoded['builds'] as $build) {
    if ($build['state'] === 'passed') {
        break;
    }

    if ($build['event_type'] === 'push') {
        $commitId = $build['commit_id'];
    }
}

if ($commitId === null) {
    return_empty_svg('No commit ID');
}

foreach ($decoded['commits'] as $commit) {
    if ($commit['id'] === $commitId) {
        $commitSha = $commit['sha'];
        break;
    }
}

if ($commitSha === null) {
    return_empty_svg('No commit SHA');
}

$url = \sprintf('https://api.github.com/repos/%s/commits/%s', $repoSlug, $commitSha);

$request = (new HttpRequest)
    ->setUri($url)
    ->setHeader('User-Agent', 'Badge Of Shame v1.0')
    ->setHeader('Accept', 'application/json');

/** @var HttpResponse $response */
$response = \Amp\wait($httpClient->request($request));

if ($response->getStatus() !== 200) {
    return_empty_svg('Github API request returned ' . $response->getStatus() . ' for ' . $url);
}

try {
    $decoded = \ExceptionalJSON\decode((string)$response->getBody(), true);
} catch (DecodeErrorException $e) {
    return_empty_svg('Github API request returned invalid JSON');
}

if (!isset($decoded['author'], $decoded['html_url'])) {
    return_empty_svg('Github API response missing data');
}

\apcu_store($apcKey, [
    'last_build_id' => $lastBuildId,
    'last_build_success' => false,
    'last_login' => $decoded['author']['login'],
    'last_url' => $decoded['html_url'],
]);

return_badge_svg($decoded['author']['login'], $decoded['html_url']);
