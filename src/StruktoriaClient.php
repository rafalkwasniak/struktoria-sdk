<?php

namespace Struktoria\Sdk;

use CURLFile;
use Struktoria\Sdk\Auth\Authenticator;
use Struktoria\Sdk\Exception\ApiException;
use Struktoria\Sdk\Http\HttpClient;
use Struktoria\Sdk\Http\Response;

/**
 * The public face of the SDK: a single object the host app talks to.
 *
 * It wires the pieces together - asks the Authenticator for a valid token,
 * builds the auth headers and delegates the actual request to HttpClient -
 * so callers never touch tokens, curl or header formatting.
 */
final class StruktoriaClient
{
    private const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private Config $config,
        private HttpClient $http,
        private Authenticator $auth,
    ) {
    }

    // -----------------------------------------------------------------
    // Documents - read the tree
    // -----------------------------------------------------------------

    /**
     * List top-level buckets.
     */
    public function buckets(int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        return $this->getDocuments('/buckets', [
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * The initial hierarchy (first level) inside a bucket.
     */
    public function hierarchyInitial(string $bucketId, int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        return $this->getDocuments('/hierarchy/initial', [
            'page' => $page,
            'pageSize' => $pageSize,
            'bucketId' => $bucketId,
        ]);
    }

    /**
     * Direct children (folders + files) of a node.
     */
    public function hierarchyChildren(string $nodeId, int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE, string $search = ''): array
    {
        $query = [
            'page' => $page,
            'pageSize' => $pageSize,
        ];
        if ('' !== $search) {
            $query['search'] = $search;
        }

        return $this->getDocuments('/hierarchy/children/'.$nodeId, $query);
    }

    /**
     * Items (files) under a node.
     */
    public function hierarchyItems(string $nodeId, int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        return $this->getDocuments('/hierarchy/items/'.$nodeId, [
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * Breadcrumb path (root -> node) for the given node.
     */
    public function hierarchyPath(string $nodeId): array
    {
        return $this->getDocuments('/hierarchy/path/'.$nodeId, []);
    }

    /**
     * List buckets through the unified hierarchy API (same result as
     * buckets(); kept for parity with the API surface).
     */
    public function hierarchyBuckets(int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        return $this->getDocuments('/hierarchy/buckets', [
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * Direct children of a folder through the folder-specific endpoint.
     */
    public function foldersChildren(string $parentId, int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE, string $search = ''): array
    {
        $query = [
            'page' => $page,
            'pageSize' => $pageSize,
        ];
        if ('' !== $search) {
            $query['search'] = $search;
        }

        return $this->getDocuments('/folders/'.$parentId.'/children', $query);
    }

    /**
     * Preview (thumbnail / metadata) of a node. Returns [] when the API has no
     * preview to offer (it answers 204 No Content in that case).
     */
    public function preview(string $nodeId): array
    {
        return $this->getDocuments('/hierarchy/preview/'.$nodeId, []);
    }

    // -----------------------------------------------------------------
    // Documents - files
    // -----------------------------------------------------------------

    /**
     * Upload a file into a node. Takes a plain path so the SDK stays free of
     * any framework's uploaded-file type; the caller passes the temp path.
     */
    public function uploadFile(string $nodeId, string $filePath, ?string $fileName = null, ?string $mimeType = null): array
    {
        $response = $this->http->postMultipart(
            $this->config->documentsUrl().'/files/upload/'.$nodeId,
            ['file' => $this->curlFile($filePath, $fileName, $mimeType)],
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * Download a file's raw bytes (not JSON-decoded).
     */
    public function downloadFile(string $fileId): string
    {
        $response = $this->http->get(
            $this->config->documentsUrl().'/files/download/'.$fileId,
            [],
            $this->authHeaders()
        );

        $this->assertOk($response);

        return (string) $response->raw();
    }

    /**
     * Download a file's raw bytes through a one-time share token. This
     * endpoint needs no Bearer (only the gateway Basic), so we send exactly
     * that. Throws on an expired link (the API answers 410 Gone).
     */
    public function sharedFile(string $token): string
    {
        $response = $this->http->get(
            $this->config->documentsUrl().'/files/shared',
            ['t' => $token],
            $this->gatewayHeaders()
        );

        $this->assertOk($response);

        return (string) $response->raw();
    }

    /**
     * Upload a temporary file (e.g. before assigning it to a conversion or a
     * process). Returns { nodeId, fileName, sizeBytes }.
     */
    public function uploadTempFile(string $filePath, ?string $fileName = null, ?string $mimeType = null): array
    {
        $response = $this->http->postMultipart(
            $this->config->documentsUrl().'/files/temp',
            ['file' => $this->curlFile($filePath, $fileName, $mimeType)],
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    // -----------------------------------------------------------------
    // Documents - create nodes
    // -----------------------------------------------------------------

    /**
     * Create a folder inside a parent node (a bucket or another folder).
     */
    public function createFolder(string $parentId, string $name): array
    {
        return $this->postDocuments('/folders', [
            'parentId' => $parentId,
            'name' => $name,
        ]);
    }

    /**
     * Create a new top-level bucket.
     *
     * NOTE: POST /buckets ignores `description` (per the API docs) - the only
     * way to set it is a follow-up PUT .../info, so we do exactly that when a
     * description is given. The created node is still what we return.
     */
    public function createBucket(string $name, ?string $description = null): array
    {
        $node = $this->postDocuments('/buckets', ['name' => $name]);

        if (null !== $description && '' !== $description && isset($node['id'])) {
            $this->updateBucketInfo((string) $node['id'], [
                'name' => $name,
                'description' => $description,
            ]);
        }

        return $node;
    }

    /**
     * Add a node through the unified hierarchy endpoint. `nodeType` is
     * `folder` or `document`.
     */
    public function addNode(string $parentId, string $name, string $nodeType = 'folder'): array
    {
        return $this->postDocuments('/hierarchy', [
            'parentId' => $parentId,
            'name' => $name,
            'nodeType' => $nodeType,
        ]);
    }

    // -----------------------------------------------------------------
    // Documents - update node metadata (rename / description / color / tags)
    // -----------------------------------------------------------------

    /**
     * Update a node's metadata (folder or file). The body is a partial info
     * map - any of name / description / color / tags. Answers 204 No Content.
     *
     * @param array<string, mixed> $info
     */
    public function updateNodeInfo(string $nodeId, array $info): array
    {
        return $this->putDocuments('/hierarchy/'.$nodeId.'/info', $info);
    }

    /**
     * Update a bucket's metadata (name / description / color / tags). 204.
     *
     * @param array<string, mixed> $info
     */
    public function updateBucketInfo(string $bucketId, array $info): array
    {
        return $this->putDocuments('/buckets/'.$bucketId.'/info', $info);
    }

    // -----------------------------------------------------------------
    // Documents - move nodes (async; returns 202 + a jobId)
    // -----------------------------------------------------------------

    /**
     * Move one or more nodes under a new parent.
     *
     * @param string[] $nodeIds
     */
    public function moveNodes(array $nodeIds, string $newParentId): array
    {
        return $this->postDocuments('/hierarchy/move', [
            'nodeIds' => array_values($nodeIds),
            'newParentId' => $newParentId,
        ]);
    }

    // -----------------------------------------------------------------
    // Documents - file text content (text / markdown / code files)
    // -----------------------------------------------------------------

    /**
     * Read a file's text content. Returns the plain string ('' when empty).
     */
    public function fileContent(string $fileId): string
    {
        $data = $this->getDocuments('/files/'.$fileId.'/content', []);

        return (string) ($data['content'] ?? '');
    }

    /**
     * Overwrite a file's text content. Answers 204 No Content.
     */
    public function updateFileContent(string $fileId, string $content): array
    {
        return $this->putDocuments('/files/'.$fileId.'/content', ['content' => $content]);
    }

    // -----------------------------------------------------------------
    // Documents - one-time share link (download without auth)
    // -----------------------------------------------------------------

    /**
     * Generate a one-time, auth-free download link for a file. The validity
     * window is 1-1440 minutes (the API rejects anything else with 400).
     *
     * @return array{url?: string, expiresAt?: string}
     */
    public function shareLink(string $fileId, int $expiresInMinutes = 15): array
    {
        return $this->postDocuments('/files/'.$fileId.'/share-link', [], [
            'expiresInMinutes' => $expiresInMinutes,
        ]);
    }

    // -----------------------------------------------------------------
    // Documents - delete nodes (async; the API returns 202 + a jobId)
    // -----------------------------------------------------------------

    /**
     * Delete a folder and all of its contents.
     */
    public function deleteFolder(string $nodeId): array
    {
        return $this->deleteDocuments('/folders/'.$nodeId);
    }

    /**
     * Delete a single file.
     */
    public function deleteFile(string $fileNodeId): array
    {
        return $this->deleteDocuments('/files/'.$fileNodeId);
    }

    /**
     * Delete a bucket and everything inside it. Unlike folders/files this
     * endpoint takes the ids in the body, not the URL, and accepts a batch.
     */
    public function deleteBucket(string $bucketId): array
    {
        $response = $this->http->deleteJson(
            $this->config->documentsUrl().'/buckets',
            ['bucketIds' => [$bucketId]],
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * Delete one or more nodes (folders/files) in a single batch.
     *
     * @param string[] $nodeIds
     */
    public function deleteNodes(array $nodeIds): array
    {
        $response = $this->http->deleteJson(
            $this->config->documentsUrl().'/hierarchy',
            ['nodeIds' => array_values($nodeIds)],
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    // -----------------------------------------------------------------
    // Documents - misc
    // -----------------------------------------------------------------

    /**
     * Seed a demo folder/file structure for the current user. Idempotent -
     * it never overwrites existing data.
     */
    public function seedDemo(): array
    {
        return $this->postDocuments('/seed/demo', []);
    }

    // -----------------------------------------------------------------
    // RAG - enable knowledge + ask questions
    // -----------------------------------------------------------------

    /**
     * One-shot import of a Documents bucket into RAG: creates (or reuses) a
     * knowledge bucket + source and queues ingest/index. Returns
     * { bucketId, sourceId, bucketPath, ingestJobId, indexJobId }.
     */
    public function importFromDocuments(string $documentsBucketNodeId, ?string $ragBucketId = null, ?string $sourceName = null): array
    {
        $body = ['documentsBucketNodeId' => $documentsBucketNodeId];
        if (null !== $ragBucketId) {
            $body['ragBucketId'] = $ragBucketId;
        }
        if (null !== $sourceName) {
            $body['sourceName'] = $sourceName;
        }

        return $this->postRag('/buckets/import-from-documents', $body);
    }

    /**
     * Ask a question against a single RAG knowledge bucket. Returns
     * { answer, chunks: [{ nodeName, bucketName, score, content, ... }] }.
     */
    public function ragChat(string $ragBucketId, string $question, ?int $topK = null, ?string $profileId = null): array
    {
        $body = ['question' => $question];
        if (null !== $topK) {
            $body['topK'] = $topK;
        }
        if (null !== $profileId) {
            $body['profileId'] = $profileId;
        }

        return $this->postRag('/buckets/'.$ragBucketId.'/chat', $body);
    }

    /**
     * Ask a question across all tenant knowledge (or a subset via
     * $options['bucketIds']). $options may also carry topK, profileId.
     *
     * @param array<string, mixed> $options
     */
    public function chat(string $question, array $options = []): array
    {
        $options['question'] = $question;

        return $this->postRag('/chat', $options);
    }

    /**
     * Semantic vector search (no LLM): returns the best-matching chunks with a
     * similarity score. $options: topK, envs, sourceTypes, bucketIds,
     * searchMode (EmbeddingOnly|Hybrid|FullBucket), embeddingWeight, keywordWeight.
     *
     * @param array<string, mixed> $options
     */
    public function search(string $query, array $options = []): array
    {
        $options['query'] = $query;

        return $this->postRag('/search', $options);
    }

    // -----------------------------------------------------------------
    // RAG - knowledge buckets
    // -----------------------------------------------------------------

    /**
     * List RAG knowledge buckets.
     */
    public function ragBuckets(int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE, string $search = ''): array
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if ('' !== $search) {
            $query['search'] = $search;
        }

        return $this->getRag('/buckets', $query);
    }

    /**
     * Create a RAG knowledge bucket. `env` is required by the API.
     */
    public function createRagBucket(string $name, string $env, ?string $description = null): array
    {
        $body = ['name' => $name, 'env' => $env];
        if (null !== $description) {
            $body['description'] = $description;
        }

        return $this->postRag('/buckets', $body);
    }

    // -----------------------------------------------------------------
    // RAG - sources
    // -----------------------------------------------------------------

    /**
     * List sources, optionally filtered by RAG bucket.
     */
    public function sources(?string $bucketId = null, int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if (null !== $bucketId) {
            $query['bucketId'] = $bucketId;
        }

        return $this->getRag('/sources', $query);
    }

    /**
     * Create a knowledge source. `configJson` is a JSON string (the API takes
     * it verbatim), e.g. '{"bucketPath":"acme/buckets/docs"}'.
     */
    public function createSource(string $bucketId, string $name, string $sourceType, ?string $configJson = null): array
    {
        $body = ['bucketId' => $bucketId, 'name' => $name, 'sourceType' => $sourceType];
        if (null !== $configJson) {
            $body['configJson'] = $configJson;
        }

        return $this->postRag('/sources', $body);
    }

    /**
     * Queue an ingest job (pull + process data from the source).
     */
    public function ingestSource(string $sourceId): array
    {
        return $this->postRag('/sources/'.$sourceId.'/ingest', []);
    }

    /**
     * Queue an index job (build embeddings for already-ingested data).
     */
    public function indexSource(string $sourceId): array
    {
        return $this->postRag('/sources/'.$sourceId.'/index', []);
    }

    /**
     * Ingest (create/update) a single Documents file into the source and queue
     * its indexing. The source must be of type `documents`.
     */
    public function ingestFile(string $sourceId, string $filePath): array
    {
        return $this->postRag('/sources/'.$sourceId.'/ingest-file', ['filePath' => $filePath]);
    }

    /**
     * Upload knowledge nodes (documents/folders) directly to a source.
     *
     * @param array<int, array<string, mixed>> $nodes
     */
    public function uploadNodes(string $sourceId, array $nodes): array
    {
        return $this->postRag('/sources/'.$sourceId.'/upload-nodes', ['nodes' => array_values($nodes)]);
    }

    // -----------------------------------------------------------------
    // RAG - chat profiles
    // -----------------------------------------------------------------

    /**
     * List chat profiles (LLM model, prompt, search mode...). Returns a list.
     */
    public function profiles(): array
    {
        return $this->getRag('/profiles', []);
    }

    /**
     * A single chat profile.
     */
    public function profile(string $profileId): array
    {
        return $this->getRag('/profiles/'.$profileId, []);
    }

    /**
     * Create a chat profile.
     *
     * @param array<string, mixed> $data
     */
    public function createProfile(array $data): array
    {
        return $this->postRag('/profiles', $data);
    }

    /**
     * Update a chat profile (only the supplied fields change).
     *
     * @param array<string, mixed> $data
     */
    public function updateProfile(string $profileId, array $data): array
    {
        return $this->putRag('/profiles/'.$profileId, $data);
    }

    /**
     * Delete a chat profile.
     */
    public function deleteProfile(string $profileId): array
    {
        return $this->deleteRag('/profiles/'.$profileId);
    }

    /**
     * Seed the predefined starter profiles (idempotent - skips existing names).
     * Returns only the newly created profiles.
     */
    public function seedProfiles(): array
    {
        return $this->postRag('/profiles/seed', []);
    }

    // -----------------------------------------------------------------
    // RAG - chat sessions (multi-turn, shareable via access token)
    // -----------------------------------------------------------------

    /**
     * Open a chat session. $options: profileId, bucketIds, expiresAt.
     * Returns a ChatSessionDto incl. the `accessToken`.
     *
     * @param array<string, mixed> $options
     */
    public function createSession(array $options = []): array
    {
        return $this->postRag('/sessions', $options);
    }

    /**
     * Session details + message history.
     */
    public function session(string $token): array
    {
        return $this->getRag('/sessions/'.$token, []);
    }

    /**
     * Close a session.
     */
    public function deleteSession(string $token): array
    {
        return $this->deleteRag('/sessions/'.$token);
    }

    /**
     * Send a question within a session (the token is the credential - no JWT
     * needed on the API side). Returns the same shape as chat.
     */
    public function sessionMessage(string $token, string $question): array
    {
        return $this->postRag('/sessions/'.$token.'/messages', ['question' => $question]);
    }

    // -----------------------------------------------------------------
    // RAG - knowledge tree (browse what is actually indexed)
    // -----------------------------------------------------------------

    public function knowledgeBuckets(int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE, string $search = ''): array
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if ('' !== $search) {
            $query['search'] = $search;
        }

        return $this->getRag('/knowledge/buckets', $query);
    }

    public function knowledgeInitial(?string $bucketId = null, int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if (null !== $bucketId) {
            $query['bucketId'] = $bucketId;
        }

        return $this->getRag('/knowledge/initial', $query);
    }

    public function knowledgeChildren(string $parentId, int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE, string $search = ''): array
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if ('' !== $search) {
            $query['search'] = $search;
        }

        return $this->getRag('/knowledge/children/'.$parentId, $query);
    }

    public function knowledgeItems(string $parentId, int $page = 0, int $pageSize = self::DEFAULT_PAGE_SIZE, string $search = ''): array
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if ('' !== $search) {
            $query['search'] = $search;
        }

        return $this->getRag('/knowledge/items/'.$parentId, $query);
    }

    public function knowledgePath(string $nodeId): array
    {
        return $this->getRag('/knowledge/path/'.$nodeId, []);
    }

    public function knowledgePreview(string $nodeId): array
    {
        return $this->getRag('/knowledge/preview/'.$nodeId, []);
    }

    /**
     * Add a node to the knowledge tree (`nodeType`: document|folder).
     */
    public function addKnowledgeNode(string $parentId, string $name, string $nodeType = 'document'): array
    {
        return $this->postRag('/knowledge', [
            'parentId' => $parentId,
            'name' => $name,
            'nodeType' => $nodeType,
        ]);
    }

    /**
     * Move knowledge nodes under a new parent.
     *
     * @param string[] $nodeIds
     */
    public function moveKnowledge(array $nodeIds, string $newParentId): array
    {
        return $this->postRag('/knowledge/move', [
            'nodeIds' => array_values($nodeIds),
            'newParentId' => $newParentId,
        ]);
    }

    /**
     * Delete knowledge nodes.
     *
     * @param string[] $nodeIds
     */
    public function deleteKnowledge(array $nodeIds): array
    {
        return $this->deleteRagJson('/knowledge', ['nodeIds' => array_values($nodeIds)]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param array<string, scalar> $query
     */
    private function getDocuments(string $path, array $query): array
    {
        $response = $this->http->get(
            $this->config->documentsUrl().$path,
            $query,
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, scalar> $query
     */
    private function postDocuments(string $path, array $data, array $query = []): array
    {
        $response = $this->http->postJson(
            $this->config->documentsUrl().$path,
            $data,
            $query,
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * @param array<string, scalar> $query
     */
    private function getRag(string $path, array $query): array
    {
        $response = $this->http->get(
            $this->config->ragUrl().$path,
            $query,
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function postRag(string $path, array $data): array
    {
        $response = $this->http->postJson(
            $this->config->ragUrl().$path,
            $data,
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function putRag(string $path, array $data): array
    {
        $response = $this->http->putJson(
            $this->config->ragUrl().$path,
            $data,
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    private function deleteRag(string $path): array
    {
        $response = $this->http->delete(
            $this->config->ragUrl().$path,
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function deleteRagJson(string $path, array $data): array
    {
        $response = $this->http->deleteJson(
            $this->config->ragUrl().$path,
            $data,
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function putDocuments(string $path, array $data): array
    {
        $response = $this->http->putJson(
            $this->config->documentsUrl().$path,
            $data,
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    private function deleteDocuments(string $path): array
    {
        $response = $this->http->delete(
            $this->config->documentsUrl().$path,
            [],
            $this->authHeaders()
        );

        return $this->expectJson($response);
    }

    /**
     * The gateway-level headers (Basic auth) that every request needs, with
     * or without a user token.
     *
     * @return array<string, string>
     */
    private function gatewayHeaders(): array
    {
        return [
            'Accept' => '*/*',
            'Authorization' => 'Basic '.base64_encode(
                $this->config->basicLogin().':'.$this->config->basicPassword()
            ),
        ];
    }

    /**
     * The headers every authenticated request needs: the gateway Basic auth
     * plus the Bearer token (fetched - and refreshed if needed - on demand).
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return $this->gatewayHeaders() + [
            'AgnetAuth' => 'Bearer '.$this->auth->accessToken(),
        ];
    }

    /**
     * Build a CURLFile for a multipart upload. Kept in one place so upload
     * and temp-upload stay consistent.
     */
    private function curlFile(string $filePath, ?string $fileName, ?string $mimeType): CURLFile
    {
        return new CURLFile(
            $filePath,
            $mimeType ?? (mime_content_type($filePath) ?: 'application/octet-stream'),
            $fileName ?? basename($filePath)
        );
    }

    /**
     * Ensure a 2xx and return the decoded JSON body as an array.
     */
    private function expectJson(Response $response): array
    {
        $this->assertOk($response);

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function assertOk(Response $response): void
    {
        if ($response->ok()) {
            return;
        }

        throw new ApiException(
            sprintf(
                'Struktoria API returned HTTP %d. Body: %s',
                $response->status(),
                substr((string) $response->raw(), 0, 300)
            ),
            $response->status()
        );
    }
}
