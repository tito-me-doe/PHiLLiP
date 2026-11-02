# Knowledge Services

## VectorStore

`Agent\VectorStore` provides bounded vector collection lifecycle and cosine-similarity search over the existing Postgres `pg_vector` extension.

### Collections
- `createCollection(string $name, int $dimension, string $model, string $ownerId): array` — create a named, owner-scoped vector collection. Dimension is validated (positive, within bound). Names are unique per owner.
- `listCollections(string $ownerId): array` — list collections owned by `$ownerId`.
- `getCollection(int $id): array` — fetch a collection by id.

### Items
- `validateVector(array $vector, int $dimension): array` — asserts the vector is numeric, finite, and matches the expected dimension. Returns normalized floats.
- `upsert(int $collectionId, string $itemId, array $vector, string $content = '', array $metadata = []): array` — insert or replace a vector item. Accepts precomputed vectors for deterministic tests. Validates dimension and finiteness.
- `delete(int $collectionId, string $itemId): array` — remove a vector item.
- `get(int $collectionId, string $itemId): array` — read a vector item (without the embedding).
- `topK(int $collectionId, array $queryVector, int $limit = 10): array` — bounded cosine-similarity search, ranked highest similarity first. Limit is clamped to `MAX_TOPK`.

### Embedding
- `embedAndUpsert(...)` — optional convenience that embeds texts via the existing `Models::embed` transport and upserts the first vector. No new transport is introduced.

### Validation
Vectors must be arrays of finite numbers matching the collection's dimension. Dimension mismatch, non-finite values (NaN/Inf), and non-numeric elements all throw `\InvalidArgumentException`.

## GraphStore

`Agent\GraphStore` provides namespace-scoped entity/alias/edge contracts with bounded read-only traversal.

### Entities
- `createEntity(string $namespace, string $name, array $properties = []): array` — create an entity. `(namespace, name)` is unique.
- `resolve(string $namespace, string $name): array` — resolve an entity. Throws `\RuntimeException` when not found or ambiguous.
- `resolveByAlias(string $alias): array` — resolve an entity by a unique alias.

### Aliases and Edges
- `createAlias(int $entityId, string $alias): array` — attach a unique alias to an entity.
- `createEdge(int $sourceId, int $targetId, string $relation, array $properties = []): array` — create or upsert a directed, typed edge between two entities.

### Read-only traversal
- `neighbors(int $entityId, string $direction = 'out', int $limit = 10): array` — bounded neighbors. Direction: `out`, `in`, or `both`. Limit clamped to `MAX_NEIGHBORS`.
- `traversal(int $entityId, int $depth = 2, int $depth = 2, int $limit = 50): array` — cycle-safe bounded traversal of outgoing edges. Depth clamped to `MAX_TRAVERSAL_DEPTH`; result count clamped to `MAX_TRAVERSAL_LIMIT`.

All writes are parameterized SQL against trusted PHP/API inputs; no arbitrary SQL is exposed.

## Schema

Migration `database/034_knowledge_services.sql` creates `vector_collections`, `vector_items`, `knowledge_entities`, `knowledge_entity_aliases`, and `knowledge_edges`, reusing the `vector` extension provisioned by `001_registry.sql`. No existing tables are modified.
