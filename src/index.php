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

    \header('Content-Type: image/svg+xml; charset=utf-8');
    exit('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="82" height="20">
    <linearGradient id="b" x2="0" y2="100%">
        <stop offset="0" stop-color="#bbb" stop-opacity=".1" />
        <stop offset="1" stop-opacity=".1" />
    </linearGradient>
    <clipPath id="a">
        <rect width="82" height="20" rx="3" fill="#fff" />
    </clipPath>
    <g clip-path="url(#a)">
        <path fill="#555" d="M0 0h39v20H0z" />
        <path fill="#e05d44" d="M39 0h43v20H39z" />
        <path fill="url(#b)" d="M0 0h82v20H0z" />
    </g>
    <g fill="#fff" text-anchor="middle" font-family="DejaVu Sans,Verdana,Geneva,sans-serif" font-size="11">
        <a xlink:href="' . $commitUrl . '">
            <text x="19.5" y="15" fill="#010101" fill-opacity=".3">' . $name . '\'s</text>
            <text x="19.5" y="14">' . $name . '\'s</text>
            <text x="59.5" y="15" fill="#010101" fill-opacity=".3">FAULT</text>
            <text x="59.5" y="14">FAULT</text>
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

if (!isset($decoded['last_build_state']) || $decoded['last_build_state'] === 'success') {
    return_empty_svg('Travis API request #1 says the build it OK (' . $decoded['last_build_state'] . ')');
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
    if ($build['status'] === 'passed') {
        break;
    }

    $commitId = $build['commit_id'];
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
    return_empty_svg('Github API request returned ' . $response->getStatus());
}

try {
    $decoded = \ExceptionalJSON\decode((string)$response->getBody(), true);
} catch (DecodeErrorException $e) {
    return_empty_svg('Github API request returned invalid JSON');
}

if (!isset($decoded['author'], $decoded['html_url'])) {
    return_empty_svg('Github API response missing data');
}

return_badge_svg($decoded['author'], $decoded['html_url']);
