<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\FcmChannel;

trait SendsFcmPush
{
    /**
     * @return list<string|class-string>
     */
    protected function channelsWithPush(array $base = ['database']): array
    {
        $channels = array_values($base);
        if (! in_array(FcmChannel::class, $channels, true)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }
}
