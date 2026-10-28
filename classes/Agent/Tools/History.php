<?php
namespace Agent\Tools;

class History
{
    /**
     * Search earlier messages in this conversation, including content omitted from the model context.
     * @param string $query Words or concepts to find in this thread
     * @param int $limit Maximum number of matching messages, from 1 to 25
     */
    public static function search(string $query, int $limit = 5)
    {
        $threadId = \Agent\ToolContext::get('thread_id');
        if (!$threadId) {
            $request = \Agent\ToolContext::get('request');
            if (!$request) throw new \RuntimeException('Current-thread history requires a conversation context');
            $matches = [];
            foreach ($request->request['body']['messages'] as $message) if (stripos(\Agent\Message::text($message), $query) !== false) $matches[] = ['id' => $message['_id'], 'role' => $message['role'], 'text' => mb_substr(\Agent\Message::text($message), 0, 4000)];
            return array_slice($matches, 0, max(1, min(25, $limit)));
        }
        return \Agent\History::search($query, $threadId, \Agent\ToolContext::get('frontend') ? \Agent\ToolContext::get('user_id') : null, $limit);
    }

    /**
     * Read one original message from this conversation by the ID returned by history search.
     * @param string $messageId Original message ID, including e: IDs for an ephemeral conversation
     */
    public static function read(string $messageId)
    {
        $threadId = \Agent\ToolContext::get('thread_id');
        if (!$threadId) {
            $request = \Agent\ToolContext::get('request');
            if ($request) foreach ($request->request['body']['messages'] as $message) if ($message['_id'] === $messageId) return $message;
            throw new \RuntimeException('Message is not in the current thread');
        }
        return \Agent\History::read($messageId, $threadId, \Agent\ToolContext::get('frontend') ? \Agent\ToolContext::get('user_id') : null);
    }
}
