<?php

namespace Traits;

trait MView
{
    use Model;

    public static function refresh() {
        self::getDb()->refresh();
    }
}