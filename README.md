# Struktoria SDK

A lightweight, framework-agnostic PHP client for the **Struktoria** (agidot) API.
It wraps authentication, the **Documents** module (hierarchical file storage:
bucket → folder → file) and the **RAG** module (vector knowledge base: semantic
search and AI chat over your documents).

- **No magic.** A thin, predictable layer over the HTTP API — one transport,
  typed value objects, explicit exceptions. Status codes are never swallowed.
- **Secrets stay outside.** The SDK takes its configuration from the host
  application; nothing is hardcoded.
- **PHP 8.0+**, only `ext-curl` and `ext-json`. No framework required (works
  anywhere; a short Laravel wiring example is included below).

## Requirements

- PHP `^8.0`
- `ext-curl`, `ext-json`

## Installation

The package lives in a private VCS repository, so declare it in your
`composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:rafalkwasniak/struktoria-sdk.git" }
    ],
    "require": {
        "rafalkwasniak/struktoria-sdk": "^1.0"
    }
}
```

```bash
composer require rafalkwasniak/struktoria-sdk
```

The machine running `composer install` needs read access to the repository
(an SSH key/deploy key, or a token in Composer's `auth.json`).

## Bootstrapping the client

```php
use Struktoria\Sdk\Config;
use Struktoria\Sdk\Http\HttpClient;
use Struktoria\Sdk\Auth\Authenticator;
use Struktoria\Sdk\Auth\InMemoryTokenStore;
use Struktoria\Sdk\StruktoriaClient;

$config = new Config(
    baseUrl:          'https://your-struktoria-host',     // API root; module URLs are derived from it
    basicLogin:       '...',                               // gateway Basic auth
    basicPassword:    '...',
    login:            '...',                               // account login
    password:         '...',
    tenantCode:       '...',
    environment:      '...',
    clientAppId:      '...',
    clientPrivateKey: '...',
    apiKey:           '...',
);

$http   = new HttpClient(timeout: 30);
$auth   = new Authenticator($config, $http, new InMemoryTokenStore());
$client = new StruktoriaClient($config, $http, $auth);
```

The `Authenticator` logs in lazily and caches the access token; it reads the
real expiry from the JWT (`exp`) rather than trusting the response envelope.

## Documents

```php
// Browse the tree
$client->buckets();                          // top-level buckets
$client->hierarchyInitial($bucketId);        // first level inside a bucket
$client->hierarchyChildren($nodeId);         // sub-folders
$client->hierarchyItems($nodeId);            // files (leaves)
$client->hierarchyPath($nodeId);             // breadcrumb (root -> node)

// Create
$client->createBucket('Documentation');
$client->createFolder($parentId, 'invoices');

// Files
$client->uploadFile($nodeId, '/tmp/report.pdf');     // plain path; CURLFile is built internally
$bytes = $client->downloadFile($fileId);
$link  = $client->shareLink($fileId, expiresInMinutes: 15);  // one-time, auth-free download URL

// Metadata (rename / description / colour / tags) — replaces the node's info
$client->updateNodeInfo($nodeId, [
    'name'        => 'Q1 report',
    'description' => 'Quarterly figures',
    'color'       => '#28c76f',
    'tags'        => ['finance', '2026'],
]);

// Move / delete (async — the API returns a jobId)
$client->moveNodes([$nodeId], $newParentId);
$client->deleteFolder($nodeId);
$client->deleteFile($fileId);
$client->deleteBucket($bucketId);
```

> Node ids are a Base64Url-encoded path that includes the name, so renaming or
> moving a node **changes its id** — re-read the tree afterwards. The `/` and
> `\` characters are not allowed in names.

## RAG (AI knowledge & chat)

A Documents bucket is connected to RAG once; new/changed files then sync
automatically.

```php
// 1) Enable AI knowledge for a Documents bucket (creates a RAG bucket + source,
//    queues ingest + indexing). Remember the returned RAG bucket id.
$res         = $client->importFromDocuments($documentsBucketNodeId);
$ragBucketId = $res['bucketId'];

// 2) Ask a question (LLM answer + the source chunks used as context)
$reply = $client->ragChat($ragBucketId, 'How do I configure authentication?');
echo $reply['answer'];
foreach ($reply['chunks'] as $chunk) {
    echo $chunk['nodeName'], ' — ', $chunk['score'], PHP_EOL;
}

// Ask across several/all knowledge buckets
$client->chat('...', ['bucketIds' => [$ragBucketId], 'topK' => 5]);

// Semantic search without an LLM (raw chunks + similarity score)
$client->search('authentication', ['topK' => 5, 'bucketIds' => [$ragBucketId]]);

// Multi-turn session (keeps history; the access token can be shared without a JWT)
$session = $client->createSession(['bucketIds' => [$ragBucketId]]);
$client->sessionMessage($session['accessToken'], 'And how does ingest work?');
```

RAG answers from the **text content** of documents — not from the file/folder
structure. The SDK also covers chat profiles (`profiles()`, `seedProfiles()`,
CRUD), sources (`sources()`, `ingestSource()`, `indexSource()`, `ingestFile()`,
`uploadNodes()`) and the knowledge tree (`knowledge*`).

## Token storage

`InMemoryTokenStore` keeps the token for the current process — fine for CLI or a
single request. To share one login across requests/workers, implement
`Struktoria\Sdk\Contracts\TokenStore` (a simple `get`/`save`/`clear`) on top of
your cache and pass it to the `Authenticator`.

```php
use Struktoria\Sdk\Contracts\TokenStore;
use Struktoria\Sdk\Auth\Token;

final class CacheTokenStore implements TokenStore
{
    public function get(): ?Token   { /* read from your cache */ }
    public function save(Token $t): void { /* write to your cache (TTL = time to expiry) */ }
    public function clear(): void   { /* forget it */ }
}
```

### Laravel

Bind the client as a singleton and back the token store with the cache:

```php
$this->app->singleton(StruktoriaClient::class, function () {
    $config = new Config(...config('struktoria'));   // values from .env
    $http   = new HttpClient(config('struktoria.http_timeout', 30));
    $auth   = new Authenticator($config, $http, new CacheTokenStore());

    return new StruktoriaClient($config, $http, $auth);
});
```

## Errors

All failures throw — catch `Struktoria\Sdk\Exception\*`:

| Exception | When |
|-----------|------|
| `TransportException` | network/curl failure (the request never completed) |
| `AuthException` | login failed (bad credentials, or the auth endpoint errored) |
| `ApiException` | the API answered a non-2xx status; `->status()` returns the HTTP code |

```php
use Struktoria\Sdk\Exception\ApiException;

try {
    $client->buckets();
} catch (ApiException $e) {
    if ($e->status() >= 500) {
        // provider-side issue — safe to retry shortly
    }
}
```

## License

Proprietary — see [LICENSE](LICENSE). The licensing terms will be revisited if
and when the package is published publicly.
