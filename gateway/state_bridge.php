<?php
// gateway/state_bridge.php — پل دسترسی گیت‌وی به state پنل (همان فایل‌ها، همان قواعد)
// گیت‌وی پروسه‌ی دائمی است؛ state را در حافظه کش می‌کند و هر N ثانیه از دیسک رفرش می‌کند
// تا تغییرات پنل (ساخت/حذف/سهمیه) بدون restart اعمال شود.

declare(strict_types=1);

// اجازه بارگذاری lib پنل (گارد WV_PANEL)
$GLOBALS['WV_PANEL'] = true;

require_once __DIR__ . '/../lib/state.php';
require_once __DIR__ . '/../lib/links.php';
require_once __DIR__ . '/../lib/stats.php';

class StateBridge
{
    /** @var array<string,array> uuid => link */
    private array $links = [];
    private int $lastLoad = 0;
    private int $ttl;
    /** trojan hash(uuid) => uuid */
    private array $trojanMap = [];
    /** ss: لیست لینک‌های فعال (ترتیب امتحان) */
    private array $ssCandidates = [];

    public function __construct(int $ttl = 3)
    {
        $this->ttl = $ttl;
        $this->refresh(true);
    }

    public function refresh(bool $force = false): void
    {
        $now = time();
        if (!$force && $now - $this->lastLoad < $this->ttl) return;
        $this->lastLoad = $now;
        $st = &state();
        $this->links = $st['links'];
        // نگاشت‌های مشتق
        $this->trojanMap = [];
        $this->ssCandidates = [];
        foreach ($this->links as $uid => $l) {
            if (!is_link_allowed($l)) continue;
            if (str_starts_with((string) $l['protocol'], 'trojan')) {
                $this->trojanMap[hash('sha224', $uid)] = $uid;
            }
            if (($l['protocol'] ?? '') === 'shadowsocks') {
                $this->ssCandidates[] = $uid;
            }
        }
        unset($st);
    }

    public function getLink(string $uuid): ?array
    {
        $this->refresh();
        $l = $this->links[$uuid] ?? null;
        if ($l && !is_link_allowed($l)) return null;
        return $l;
    }

    public function findByTrojanHash(string $pwHash): ?string
    {
        $this->refresh();
        return $this->trojanMap[$pwHash] ?? null;
    }

    /** @return array<string> uuidهای فعال shadowsocks */
    public function ssCandidates(): array
    {
        $this->refresh();
        return $this->ssCandidates;
    }

    /** مصرف ترافیک: چک سهمیه + ثبت (نوشتن مستقیم در state مشترک پنل) */
    public function consume(string $uuid, int $bytes): bool
    {
        return check_and_use($uuid, $bytes);
    }

    /** ثبت اتصال زنده برای صفحه‌ی اتصالات پنل */
    public function trackConn(string $uuid, string $ip, string $transport): void
    {
        track_connection($uuid, $ip, $transport);
    }
}
