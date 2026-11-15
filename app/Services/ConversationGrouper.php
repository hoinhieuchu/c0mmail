<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Groups only messages connected by RFC Message-ID, In-Reply-To, or References.
 * It deliberately does not guess from subject lines, which often collide.
 */
final class ConversationGrouper
{
    public static function group(array $messages): array
    {
        $parentById = [];
        foreach ($messages as $message) {
            $id = self::messageId((string)($message['message_id'] ?? ''));
            if ($id === '') continue;
            $references = self::messageIds((string)($message['references'] ?? ''));
            $parent = $references[0] ?? self::messageId((string)($message['in_reply_to'] ?? ''));
            if ($parent !== '' && $parent !== $id) $parentById[$id] = $parent;
        }

        $groups = [];
        foreach ($messages as $message) {
            $id = self::messageId((string)($message['message_id'] ?? ''));
            $references = self::messageIds((string)($message['references'] ?? ''));
            $parent = $references[0] ?? self::messageId((string)($message['in_reply_to'] ?? ''));
            $root = $id !== ''
                ? self::resolveRoot($id, $parentById)
                : ($parent !== '' ? self::resolveRoot($parent, $parentById) : 'uid:' . (int)($message['uid'] ?? 0));
            $groups[$root][] = $message;
        }

        $result = [];
        foreach ($groups as $items) {
            usort($items, static fn(array $a, array $b): int => (int)($b['uid'] ?? 0) <=> (int)($a['uid'] ?? 0));
            $latest = $items[0];
            $latest['thread_count'] = count($items);
            $latest['thread_uids'] = array_values(array_map(static fn(array $item): int => (int)$item['uid'], $items));
            $latest['thread_items'] = array_map(static fn(array $item): array => [
                'uid' => (int)($item['uid'] ?? 0),
                'subject' => (string)($item['subject'] ?? ''),
                'from' => (string)($item['from'] ?? ''),
                'date' => (string)($item['date'] ?? ''),
            ], $items);
            $result[] = $latest;
        }

        usort($result, static fn(array $a, array $b): int => (int)($b['uid'] ?? 0) <=> (int)($a['uid'] ?? 0));
        return $result;
    }

    private static function resolveRoot(string $id, array $parentById): string
    {
        $seen = [];
        while (isset($parentById[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = $parentById[$id];
        }
        return $id;
    }

    private static function messageIds(string $value): array
    {
        if (!preg_match_all('/<[^<>\s]+>/', $value, $matches)) return [];
        return array_values(array_unique($matches[0]));
    }

    private static function messageId(string $value): string
    {
        $ids = self::messageIds($value);
        return $ids[0] ?? '';
    }
}
