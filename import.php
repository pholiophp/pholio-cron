<?php

define('ACCESS_TOKEN', 'YOUR TOKEN VALUE');

function stop($message)
{
    fputs(STDERR, "{$message}\nUsage: php import.php \"owner/repository\"\n");
    exit(1);
}

function getTags($tagsResponse)
{
    $tags = ['master'];
    $tagNames = array_column($tagsResponse, 'name');
    sort($tagNames);
    var_export($tagNames);
    $curr = null;
    $prev = null;
    foreach (array_reverse($tagNames) as $tagName) {
        list($major, $minor, $patch) = explode('.', $tagName) + [1 => null, 2 => null];
        $prev = $curr;
        $curr = $major;
        if ($prev !== $curr) {
            $tags[] = $tagName;
        }
    }

    return $tags;
}

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client as GuzzleClient;
use MongoDB\Client as MongoClient;

$guzzleClient = new GuzzleClient(
    [
        'base_url' => 'https://api.github.com/',
        'defaults' => [
            'headers' => ['Authorization' => 'token ' .  ACCESS_TOKEN],
        ],
    ]
);

$guzzleClient->setDefaultOption('verify', false);

$url = 'mongodb://localhost:27017';
$libraries = (new MongoClient(
    $url,
    [],
    ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
))->selectDatabase('pholio')->selectCollection('libraries');

if (!isset($argv[1])) {
    stop('No repository provided');
};

$repository = $argv[1];

$workDir = __DIR__ . '/temp';

echo "Removing work directory\n";
exec("rm -rf {$workDir}");
$repositoryData = $guzzleClient->get("/repos/{$repository}")->json();

echo "Cloning {$repository}\n";
$command = "git clone {$repositoryData['clone_url']} {$workDir}";
$result = null;

passthru($command, $result);

$composer = json_decode(file_get_contents($workDir . '/composer.json'), true);
list($owner, $package) = explode('/', $composer['name']);
$keywords = $composer['keywords'];
$description = $composer['description'];
$id = "{$owner}-{$package}";

echo "Document id is {$id}\n";
$mongoDocument = [
    '_id' => $id,
    'owner' => $owner,
    'package' => $package,
    'repository' => $repositoryData['full_name'],
    'source' => $repositoryData['html_url'],
    'stars' => $repositoryData['stargazers_count'],
    'watchers' => $repositoryData['subscribers_count'],
    'forks' => $repositoryData['forks_count'],
    'issues' => $repositoryData['open_issues_count'],
    'avatar' => $repositoryData['owner']['avatar_url'],
    'keywords' => $keywords,
    'description' => $description,
    'tags' => [],
];

$tags = getTags($guzzleClient->get("/repos/{$repository}/tags")->json());
foreach ($tags as $tagName) {
    chdir($workDir);
    echo "Checking out tag {$tagName}\n";
    exec("git checkout {$tagName}");

    if ($tagName === 'master') {
        $major = 'dev-master';
    } else {
        list($major, $minor, $patch) = explode('.', $tagName) + [1 => null, 2 => null];
    }

    chdir(__DIR__);

    $arguments = [
        "--target={$workDir}/output",
        '--template=xml',
        '--visibility=public',
        '--no-interaction',
        "--directory={$workDir}/src",
        '-vvv',
    ];

    $command = 'phpdoc ' . implode(' ', $arguments);

    echo "{$command}\n";
    echo "Building phpdoc\n";
    passthru($command, $returnStatus);

    echo "Building mongo document\n";
    $domDocument = new \DOMDocument();
    $domDocument->formatOutput = false;
    $domDocument->preserveWhiteSpace = false;
    $domDocument->load("{$workDir}/output/structure.xml");

    $mongoDocument[$major] = $domDocument->saveXml();

    $mongoDocument['tags'][] = $major;
}

$library = $libraries->findOne(['_id' => $id]);
if ($library !== null) {
    echo "Removing existing document\n";
    $libraries->deleteOne(['_id' => $id]);
}

echo "Inserting new document\n";
$libraries->insertOne($mongoDocument);
