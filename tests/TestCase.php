<?php

namespace BoldMinded\Dexter\Tests;

use BoldMinded\Dexter\ServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * Boots a minimal Statamic app around the addon via Testbench, so the suite
 * runs from the addon repo without a host site.
 *
 * PreventsSavingStacheItemsToDisk redirects every Stache write to a scratch
 * directory that is wiped between tests, so users created to exercise the
 * permission boundary leave nothing behind in tests/__fixtures__.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Users live in the fixture directory AddonTestCase points the Stache
        // at, so test users never touch a real site's users/.
        $app['config']->set('statamic.users.repository', 'file');

        // Tests create a user per role to prove the permission boundary, and
        // the free edition caps a site at one user.
        $app['config']->set('statamic.editions.pro', true);
    }
}
