<?php

use App\PersonalAccess;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

pest()->use(LazilyRefreshDatabase::class)->in('Feature/SessionControllerTest.php', 'Feature/GameControllerTest.php', 'Feature/IgdbControllerTest.php');
pest()->use(DatabaseMigrations::class)->in('Feature/MediaAndBackupTest.php');

function personalSession(): array
{
    return ['personal_access' => app(PersonalAccess::class)->fingerprint()];
}

function igdbFixture(): array
{
    return ['id' => 1942, 'name' => 'God of War', 'summary' => 'A father and son journey through the Norse realms.', 'url' => 'https://www.igdb.com/games/god-of-war', 'first_release_date' => 1524182400, 'cover' => ['image_id' => 'cofixture'], 'artworks' => [['image_id' => 'arfixture']], 'screenshots' => [['image_id' => 'scfixture']], 'platforms' => [['id' => 167, 'name' => 'PlayStation 5', 'abbreviation' => 'PS5']], 'genres' => [['name' => 'Adventure']]];
}
