<?php
namespace Agent;

/**
 * Administrator-configured data-exit policy.
 *
 * Default state is inactive: every outbound category resolves to 'allow' and
 * current behavior is preserved. When active, each category resolves to
 * allow / obfuscate / block and enforcement happens at the outbound seams
 * (tool, model, broker) rather than through client-side warnings.
 *
 * Policy is stored in the existing settings table, so no new migration is
 * required. Configuration is an administrator concern and is enforced
 * automatically; a user selecting an endpoint cannot bypass it.
 */
class DataExitPolicy
{
    public const ACTIVE_KEY = 'data_exit.active';
    public const POLICY_KEY = 'data_exit.policy';

    public const CATEGORY_MODEL = 'model.outbound';
    public const CATEGORY_TOOL = 'tool.outbound';
    public const CATEGORY_BROKER = 'broker.outbound';

    public const ACTION_ALLOW = 'allow';
    public const ACTION_OBFUSCATE = 'obfuscate';
    public const ACTION_BLOCK = 'block';

    private static $active = false;
    private static $categories = [
        self::CATEGORY_MODEL => self::ACTION_ALLOW,
        self::CATEGORY_TOOL => self::ACTION_ALLOW,
        self::CATEGORY_BROKER => self::ACTION_ALLOW,
    ];
    private static $loaded = false;

    /** Known outbound categories. Administrators configure them; users cannot extend them. */
    public static function categories(): array
    {
        self::load();
        return self::$categories;
    }

    public static function isActive(): bool
    {
        self::load();
        return self::$active;
    }

    /** Resolve the enforced action for one outbound category. */
    public static function decision(string $category): string
    {
        self::load();
        if (!isset(self::$categories[$category])) {
            throw new \InvalidArgumentException('Unknown data-exit category: ' . $category);
        }
        return self::$categories[$category];
    }

    /**
     * Configure the active state and per-category actions.
     *
     * Administrator-only at the call site. Unknown categories or actions are
     * rejected outright. The policy is held in memory for this request and
     * persisted to the existing settings table when available.
     */
    public static function configure(array $policy): array
    {
        self::load();
        $active = isset($policy['active']) ? Config::boolean($policy['active'], 'data_exit.active') : false;
        unset($policy['active']);

        $categories = self::$categories;
        foreach ($policy as $category => $action) {
            if (!is_string($category) || !in_array($category, [self::CATEGORY_MODEL, self::CATEGORY_TOOL, self::CATEGORY_BROKER], true)) {
                throw new \InvalidArgumentException('Unknown data-exit category: ' . var_export($category, true));
            }
            if (!in_array($action, [self::ACTION_ALLOW, self::ACTION_OBFUSCATE, self::ACTION_BLOCK], true)) {
                throw new \InvalidArgumentException('Invalid data-exit action for ' . $category . ': ' . var_export($action, true));
            }
            $categories[$category] = $action;
        }

        self::$active = $active;
        self::$categories = $categories;
        self::persist($active, $categories);

        return ['active' => $active, 'policy' => $categories];
    }

    /** Reset in-memory policy to defaults (test helper; use defaults, do not re-read settings). */
    public static function reset(): void
    {
        self::$active = false;
        self::$categories = [
            self::CATEGORY_MODEL => self::ACTION_ALLOW,
            self::CATEGORY_TOOL => self::ACTION_ALLOW,
            self::CATEGORY_BROKER => self::ACTION_ALLOW,
        ];
        self::$loaded = true;
    }

    /**
     * Enforce one outbound category at an outbound seam.
     *
     * Returns true when passage is allowed (allow or obfuscate). Throws a
     * recoverable \RuntimeException when the category blocks the exit, so the
     * tool/model/broker caller can convert it to the standard error envelope and
     * the underlying action never runs.
     */
    public static function enforce(string $category): bool
    {
        if (!self::isActive()) {
            return true;
        }
        $action = self::decision($category);
        if ($action === self::ACTION_BLOCK) {
            throw new \RuntimeException('Data exit policy blocks ' . $category);
        }
        return true;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        try {
            $q = Database::connection()->prepare('SELECT value FROM settings WHERE key = ?');
            $q->execute([self::ACTIVE_KEY]);
            $row = $q->fetch();
            if ($row !== false) {
                $value = json_decode((string)$row['value'], true);
                if (is_bool($value)) {
                    self::$active = $value;
                }
            }
            $q = Database::connection()->prepare('SELECT value FROM settings WHERE key = ?');
            $q->execute([self::POLICY_KEY]);
            $row = $q->fetch();
            if ($row !== false) {
                $value = json_decode((string)$row['value'], true);
                if (is_array($value)) {
                    $known = [self::CATEGORY_MODEL, self::CATEGORY_TOOL, self::CATEGORY_BROKER];
                    $merged = [];
                    foreach ($known as $category) {
                        $merged[$category] = isset($value[$category]) && in_array($value[$category], [self::ACTION_ALLOW, self::ACTION_OBFUSCATE, self::ACTION_BLOCK], true)
                            ? $value[$category]
                            : self::ACTION_ALLOW;
                    }
                    self::$categories = $merged;
                }
            }
        } catch (\Exception $e) {
            // Database unavailable in this request: keep in-memory defaults.
        }
    }

    private static function persist(bool $active, array $categories): void
    {
        try {
            $db = Database::connection();
            $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?::jsonb) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value')->execute([self::ACTIVE_KEY, json_encode($active)]);
            $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?::jsonb) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value')->execute([self::POLICY_KEY, json_encode($categories)]);
        } catch (\Exception $e) {
            Log::writeWarning('Data-exit policy not persisted to settings: %s', $e->getMessage());
        }
    }
}
