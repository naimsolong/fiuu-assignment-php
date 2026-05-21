<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
}
