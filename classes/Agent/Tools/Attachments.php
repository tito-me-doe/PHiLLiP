<?php
namespace Agent\Tools;

class Attachments
{
    /** List files attached to the current conversation, including files outside the model's visible context. */
    public static function listFiles()
    {
        $threadId = \Agent\ToolContext::get('thread_id');
        if (!$threadId) {
            $request = \Agent\ToolContext::get('request'); $files = [];
            if ($request) foreach ($request->request['body']['messages'] as $message) foreach (isset($message['attachments']) ? $message['attachments'] : [] as $file) $files[$file['id']] = $file;
            if (!$request) throw new \RuntimeException('Attachment retrieval requires a conversation context');
            return array_values($files);
        }
        if (\Agent\ToolContext::get('frontend')) \Agent\Access::read(\Agent\ToolContext::get('user_id'), new \Agent\Thread($threadId));
        return \Agent\Attachments::threadFiles($threadId, \Agent\ToolContext::get('run_id'));
    }
    /**
     * Read an attached text file in bounded character ranges. Use this for content beyond an initial excerpt.
     * @param string $attachmentId File identifier returned with an attachment or by listFiles
     * @param int $offset Starting character offset, zero-based
     * @param int $length Number of characters to return, at most 8000
     */
    public static function read(string $attachmentId, int $offset = 0, int $length = 8000)
    {
        $files = self::listFiles();
        if (!in_array($attachmentId, array_column($files, 'id'), true)) throw new \RuntimeException('Attachment is not in the current conversation');
        $row = \Agent\Attachments::get($attachmentId);
        if ($row['text_content'] === null) {
            $control = function () {
                $runId = \Agent\ToolContext::get('run_id');
                return $runId ? (int)\Agent\Run::get($runId)['stop_level'] : 0;
            };
            $parsed = \Agent\AttachmentParser::render($row, ['control' => $control], []);
            $row['text_content'] = $parsed['text'];
        }
        $offset = max(0, $offset); $length = max(1, min(8000, $length));
        return ['id' => $attachmentId, 'name' => $row['name'], 'offset' => $offset, 'text' => mb_substr($row['text_content'], $offset, $length), 'total_characters' => mb_strlen($row['text_content'])];
    }
}
