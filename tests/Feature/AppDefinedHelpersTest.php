<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Middleware\SetLocale;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * An app may define its own get_current_locale() / get_supported_languages()
 * (the package's helpers are unprefixed and only defined when missing). The
 * package's own code must not call them, so its behavior doesn't change.
 *
 * The outer test runs the inner one in a child PHP process whose
 * auto_prepend_file defines both functions before Composer loads the package.
 */
class AppDefinedHelpersTest extends TestCase
{
    public function test_package_behavior_is_unchanged_when_the_app_defines_the_helpers()
    {
        $process = new Process([
            PHP_BINARY,
            '-d', 'auto_prepend_file='.realpath(__DIR__.'/../fixtures/app-defined-helpers.php'),
            'vendor/bin/phpunit',
            '--no-coverage',
            '--do-not-cache-result',
            '--group', 'app-defined-helpers',
            'tests/Feature/AppDefinedHelpersTest.php',
        ], realpath(__DIR__.'/../..'));
        $process->setTimeout(120)->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('OK (1 test', $process->getOutput());
    }

    #[Group('app-defined-helpers')]
    public function test_inner()
    {
        if (! defined('TRANSLAGENIUS_APP_HELPERS')) {
            $this->markTestSkipped('Runs only in the child process started by the test above.');
        }

        // The app's versions are the ones defined.
        $this->assertSame('app-current-locale', get_current_locale());
        $this->assertSame(['app-language'], get_supported_languages());

        Sleep::fake();
        Http::fake(function (Request $request) {
            preg_match('/from (\w+) to (\w+)\./', $request['messages'][0]['content'], $m);

            return Http::response(['choices' => [['message' => ['content' => "{$request['messages'][1]['content']}|{$m[1]}>{$m[2]}"], 'finish_reason' => 'stop']]]);
        });

        // SetLocale, the dispatcher's source locale, the job's targets.
        $request = new HttpRequest;
        $request->headers->set('Accept-Language', 'ar');
        app(SetLocale::class)->handle($request, fn () => new Response);
        $this->assertSame('ar', app()->getLocale());

        $model = TestTranslatableModel::create(['name' => ['ar' => 'مرحبا'], 'description' => ['ar' => 'وصف']]);
        $name = TestTranslatableModel::find($model->id)->getTranslations('name');
        ksort($name);
        $this->assertSame(['ar' => 'مرحبا', 'en' => 'مرحبا|ar>en', 'es' => 'مرحبا|ar>es'], $name);

        // The scopes and an edit's re-translation.
        $this->assertSame(1, TestTranslatableModel::fullyTranslated('ar')->count());
        TestTranslatableModel::find($model->id)->setTranslation('name', 'ar', 'أهلا')->save();
        $this->assertSame('أهلا|ar>es', TestTranslatableModel::find($model->id)->getTranslation('name', 'es', false));

        // Backfill.
        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--source' => 'ar', '--dry-run' => true])
            ->expectsOutputToContain('target locale(s): en, es')
            ->assertSuccessful();
    }
}
