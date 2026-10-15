<?php

namespace App\Services\DailyWork\History;

/**
 * Who and what is behind the change being saved, read by DailyWorkHistoryObserver. A write path that knows more than the
 * request does (the import, a batch reversal, a console command) wraps its work in with(); everything else is classified
 * from the request: the mobile app talks to /api/v1, everything else on the web is "web".
 */
final class DailyWorkChangeContext
{
    public const WEB = 'web';

    public const IMPORT = 'import';

    public const API = 'api';

    public const MOBILE = 'mobile';

    public const CONSOLE = 'console';

    private static ?string $source = null;

    private static ?int $batchId = null;

    private static ?string $reason = null;

    private static ?string $actor = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function with(string $source, ?int $batchId, callable $callback, ?string $reason = null, ?string $actor = null): mixed
    {
        $prev = [self::$source, self::$batchId, self::$reason, self::$actor];
        [self::$source, self::$batchId, self::$reason, self::$actor] = [$source, $batchId, $reason, $actor];

        try {
            return $callback();
        } finally {
            [self::$source, self::$batchId, self::$reason, self::$actor] = $prev;
        }
    }

    public static function source(): string
    {
        if (self::$source !== null) {
            return self::$source;
        }
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return self::CONSOLE;
        }
        $request = request();
        if ($request->is('api/*')) {
            return str_starts_with(strtolower((string) $request->header('X-Client', '')), 'mobile') ? self::MOBILE : self::API;
        }

        return self::WEB;
    }

    public static function batchId(): ?int
    {
        return self::$batchId;
    }

    public static function reason(): ?string
    {
        return self::$reason;
    }

    public static function actor(): ?string
    {
        return self::$actor ?? (auth()->id() !== null ? (string) auth()->id() : null);
    }
}
