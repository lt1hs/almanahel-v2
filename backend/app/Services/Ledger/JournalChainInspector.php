<?php

namespace App\Services\Ledger;

use App\Models\JournalEntry;

final class JournalChainInspector
{
    public static function isReversal(JournalEntry $entry): bool
    {
        return $entry->reverses_entry_id !== null
            || str_ends_with((string) $entry->event_type, '_reversal');
    }

    /**
     * @return list<string>
     */
    public function issues(): array
    {
        $issues = [];
        $all = JournalEntry::query()->orderBy('id')->get();
        $byId = $all->keyBy('id');
        $byKey = $all->groupBy(fn (JournalEntry $e) => $this->key($e));

        $activeNonReversal = $all
            ->filter(fn (JournalEntry $e) => $e->status === 'active' && !self::isReversal($e))
            ->groupBy(fn (JournalEntry $e) => $this->key($e));
        foreach ($activeNonReversal as $key => $rows) {
            if ($rows->count() > 1) {
                $issues[] = "multiple active non-reversal journals: {$key} ({$rows->count()})";
            }
        }

        $revTargets = $all->whereNotNull('reverses_entry_id')->pluck('reverses_entry_id');
        if ($revTargets->count() !== $revTargets->unique()->count()) {
            $issues[] = 'duplicate reverses_entry_id values';
        }

        foreach ($byKey as $key => $rows) {
            $versions = $rows->pluck('version')->map(fn ($v) => (int) $v)->sort()->values();
            $unique = $versions->unique()->values();
            if ($unique->count() !== $versions->count()) {
                $issues[] = "duplicate journal versions: {$key}";
                continue;
            }
            $max = (int) ($unique->max() ?: 0);
            if ($max > 0 && $unique->all() !== range(1, $max)) {
                $issues[] = "journal version gap: {$key}";
            }
        }

        foreach ($all as $entry) {
            if ($entry->status === 'reversed' && $entry->reversed_at === null) {
                $issues[] = "reversed journal {$entry->id} missing reversed_at";
            }
            if (self::isReversal($entry)) {
                if (!$entry->reverses_entry_id) {
                    $issues[] = "reversal {$entry->id} missing reverses_entry_id";
                } else {
                    $target = $byId->get($entry->reverses_entry_id);
                    if (!$target) {
                        $issues[] = "reversal {$entry->id} points at missing journal";
                    } else {
                        if ($target->status !== 'reversed') {
                            $issues[] = "reversal {$entry->id} target is not reversed";
                        }
                        if ($target->source_type !== $entry->source_type || (int) $target->source_id !== (int) $entry->source_id) {
                            $issues[] = "reversal {$entry->id} source identity mismatch";
                        }
                        if ($target->currency !== $entry->currency) {
                            $issues[] = "reversal {$entry->id} currency mismatch";
                        }
                    }
                }
            }
            if ($entry->supersedes_entry_id) {
                $prev = $byId->get($entry->supersedes_entry_id);
                if (!$prev) {
                    $issues[] = "journal {$entry->id} supersedes missing entry";
                } else {
                    if ($prev->source_type !== $entry->source_type
                        || (int) $prev->source_id !== (int) $entry->source_id
                        || $prev->event_type !== $entry->event_type) {
                        $issues[] = "journal {$entry->id} supersedes different source/event";
                    }
                    if ((int) $prev->version !== (int) $entry->version - 1) {
                        $issues[] = "journal {$entry->id} does not supersede immediately previous version";
                    }
                    if ($prev->status !== 'reversed') {
                        $issues[] = "superseded journal {$prev->id} is not reversed";
                    }
                }
            }
        }

        foreach ($all as $entry) {
            $seen = [];
            $cur = $entry;
            while ($cur && $cur->supersedes_entry_id) {
                if (isset($seen[$cur->id])) {
                    $issues[] = "supersession cycle at journal {$entry->id}";
                    break;
                }
                $seen[$cur->id] = true;
                $cur = $byId->get($cur->supersedes_entry_id);
            }
        }

        return array_values(array_unique($issues));
    }

    private function key(JournalEntry $entry): string
    {
        return $entry->source_type . '|' . $entry->source_id . '|' . $entry->event_type;
    }
}
