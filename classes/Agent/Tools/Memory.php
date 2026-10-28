<?php
namespace Agent\Tools;

class Memory
{
    /**
     * Remember a durable fact, preference, decision, or relationship with a source in this thread.
     * @param string $content One self-contained statement worth remembering
     * @param string $subject Person, entity, or topic the statement concerns
     * @param string $relation Optional relationship verb such as uses or prefers
     * @param string $object Optional related entity or value
     * @param int $sourceMessageId Source message ID, or zero to use the latest user message
     */
    public static function remember(string $content, string $subject = '', string $relation = '', string $object = '', int $sourceMessageId = 0)
    {
        $threadId = \Agent\ToolContext::get('thread_id');
        if (!$threadId) throw new \RuntimeException('Memory requires a thread context');
        $ownerId = \Agent\ToolContext::get('user_id', \Agent\ToolContext::get('memory_owner', 'application'));
        if (!$sourceMessageId) {
            $messages = (new \Agent\Thread($threadId))->messages(\Agent\ToolContext::get('run_id'));
            foreach (array_reverse($messages) as $message) {
                if ($message['role'] === 'user' && $message['author_id'] !== null) { $sourceMessageId = (int)$message['id']; break; }
            }
        }
        $id = \Agent\Knowledge::remember($threadId, $ownerId, $content, $subject, [$sourceMessageId], $relation, $object);
        return ['id' => $id, 'remembered' => $content, 'source' => $sourceMessageId];
    }

    /**
     * Recall durable knowledge from this thread and other conversations you have permission to access.
     * @param string $query Person, topic, or wording to recall
     * @param int $limit Maximum number of memories, from 1 to 25
     */
    public static function recall(string $query, int $limit = 5) { return \Agent\Knowledge::recall($query, self::user(), null, max(1, min(25, $limit))); }

    /**
     * Search original messages across conversations you have permission to access.
     * @param string $query Words or concepts from a past conversation
     * @param int $limit Maximum number of messages, from 1 to 25
     */
    public static function searchConversations(string $query, int $limit = 5) { return \Agent\History::search($query, null, self::user(), $limit); }

    /**
     * Read a complete original message found by memory search, including from another accessible thread.
     * @param int $messageId Message identifier returned by search
     */
    public static function readMessage(int $messageId) { return \Agent\History::read($messageId, null, self::user()); }

    /**
     * Traverse known entity relationships, using only knowledge you have permission to access.
     * @param string $entity Entity name or topic to start from
     * @param int $depth Number of relationship steps, from 1 to 4
     */
    public static function traverse(string $entity, int $depth = 2) { return \Agent\Knowledge::graph($entity, self::user(), null, $depth); }

    /**
     * Replace an owned memory with a corrected statement supported by the latest user message in this thread.
     * @param int $memoryId Identifier of the memory to correct
     * @param string $content Corrected self-contained statement
     */
    public static function revise(int $memoryId, string $content)
    {
        $old = self::owned($memoryId);
        $saved = self::remember($content, $old['subject']);
        if ($saved['id'] != $memoryId) \Agent\Database::connection()->prepare('DELETE FROM claims WHERE id = ?')->execute([$memoryId]);
        return $saved;
    }

    /**
     * Forget an owned memory. Source messages remain in conversation history.
     * @param int $memoryId Identifier of the memory to forget
     */
    public static function forget(int $memoryId)
    {
        self::owned($memoryId);
        \Agent\Database::connection()->prepare('DELETE FROM claims WHERE id = ?')->execute([$memoryId]);
        return ['id' => $memoryId, 'forgotten' => true];
    }

    private static function user()
    {
        if (\Agent\ToolContext::get('memory_restricted')) return \Agent\ToolContext::get('user_id') ?: new \Agent\MemoryAudience(\Agent\ToolContext::get('thread_id'));
        return \Agent\ToolContext::get('frontend') ? \Agent\ToolContext::get('user_id') : null;
    }

    private static function owned($id)
    {
        $claim = \Agent\Knowledge::get($id, self::user());
        if ((\Agent\ToolContext::get('frontend') || \Agent\ToolContext::get('memory_restricted')) && $claim['owner_id'] !== (string)\Agent\ToolContext::get('user_id', \Agent\ToolContext::get('memory_owner'))) throw new \RuntimeException('Only the memory owner may change it');
        return $claim;
    }
}
