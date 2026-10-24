<?php

namespace Mendbird\Helper;

use Illuminate\Console\Command;

/** Prints exactly what the helper sends, so you can check it before Mendbird ever asks. */
class ShowCommand extends Command
{
    protected $signature = 'mendbird:show';

    protected $description = 'Show exactly what the Mendbird helper sends';

    public function handle(): int
    {
        $this->line(json_encode(Lister::list(base_path()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
