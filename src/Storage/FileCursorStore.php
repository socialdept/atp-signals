<?php

namespace SocialDept\AtpSignals\Storage;

use Illuminate\Support\Facades\File;
use SocialDept\AtpSignals\Contracts\CursorStore;

class FileCursorStore implements CursorStore
{
    protected string $path;

    /**
     * @param  string|null  $suffix  Appended to the filename stem, so each consumer
     *                               mode keeps an independent position.
     */
    public function __construct(?string $suffix = null)
    {
        $this->path = config('atp-signals.cursor_config.file.path', storage_path('signal/cursor.json'));

        if ($suffix) {
            $extension = pathinfo($this->path, PATHINFO_EXTENSION);
            $stem = $extension ? substr($this->path, 0, -(strlen($extension) + 1)) : $this->path;
            $this->path = $stem.'-'.$suffix.($extension ? ".{$extension}" : '');
        }

        // Ensure directory exists
        $directory = dirname($this->path);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    public function get(): ?int
    {
        if (! File::exists($this->path)) {
            return null;
        }

        $data = json_decode(File::get($this->path), true);

        return $data['cursor'] ?? null;
    }

    public function set(int $cursor): void
    {
        File::put($this->path, json_encode([
            'cursor' => $cursor,
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }

    public function clear(): void
    {
        if (File::exists($this->path)) {
            File::delete($this->path);
        }
    }
}
