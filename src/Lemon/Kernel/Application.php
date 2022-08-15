<?php

declare(strict_types=1);

namespace Lemon\Kernel;

use Error;
use ErrorException;
use Exception;
use Lemon\Http\Request;
use Lemon\Protection\Middlwares\Csrf;
use Lemon\Routing\Router;
use Lemon\Support\Filesystem;
use Lemon\Support\Types\Str;
use Lemon\Zest;
use Throwable;

/**
 * The Lemon Application.
 */
final class Application extends Container
{
    /**
     * Current Lemon version.
     */
    public const VERSION = '3.0.2';

    /**
     * Default units with aliases.
     */
    private const DEFAULTS = [
        \Lemon\Routing\Router::class => ['routing'],
        \Lemon\Config\Config::class => ['config'],
        \Lemon\Cache\Cache::class => ['cache', \Psr\SimpleCache\CacheInterface::class],
        \Lemon\Templating\Juice\Compiler::class => ['juice', \Lemon\Templating\Compiler::class],
        \Lemon\Templating\Factory::class => ['templating'],
        \Lemon\Support\Env::class => ['env'],
        \Lemon\Http\ResponseFactory::class => ['response'],
        \Lemon\Http\Session::class => ['session'],
        \Lemon\Protection\Csrf::class => ['csrf'],
        \Lemon\Debug\Handling\Handler::class => ['handler'],
        \Lemon\Terminal\Terminal::class => ['terminal'],
        \Lemon\Debug\Dumper::class => ['dumper'],
        \Lemon\Events\Dispatcher::class => ['events'],
        \Lemon\Logging\Logger::class => ['log', \Psr\Log\LoggerInterface::class],
        \Lemon\Database\Database::class => ['database'],
        \Lemon\Validation\Validator::class => ['validation'],
    ];

    /**
     * App directory.
     */
    public readonly string $directory;

    /**
     * Creates new application instance.
     */
    public function __construct(string $directory)
    {
        $this->directory = $directory;
        $this->add(self::class, $this);
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Registers default services.
     */
    public function loadServices(): void
    {
        foreach (self::DEFAULTS as $unit => $aliases) {
            $this->add($unit);
            foreach ($aliases as $alias) {
                $this->alias($alias, $unit);
            }
        }
    }

    /**
     * Initializes zests.
     */
    public function loadZests(): void
    {
        Zest::init($this);
    }

    /**
     * Loads error/exception handlers.
     */
    public function loadHandler(): void
    {
        error_reporting(E_ALL);
        set_exception_handler([$this, 'handle']);
    }

    /**
     * Executes error handler.
     */
    public function handle(Throwable $problem): void
    {
        if ($problem instanceof Error) {
            $this->handle(new ErrorException($problem->getMessage(), 0, $problem->getCode(), $problem->getFile(), $problem->getLine()));
        }
        $this->get('handler')->handle($problem);

        exit;
    }

    /**
     * Loads fundamental commands.
     */
    public function loadCommands(): void
    {
        $commands = new Commands($this->get('terminal'), $this->get('config'), $this);
        $commands->load();
    }

    /**
     * Returns path of specific file in current project.
     */
    public function file(string $path, string $extension = null): string
    {
        $dir = Filesystem::join(
            $this->directory,
            Str::replace($path, '.', DIRECTORY_SEPARATOR)->value
        );

        $dir = Filesystem::normalize($dir);

        if ($extension) {
            return $dir.'.'.trim($extension, " \t\n\r.");
        }

        return $dir;
    }

    public function runsInTerminal(): bool
    {
        return PHP_SAPI == 'cli';
    }

    /**
     * Executes whole application.
     */
    public function boot(): void
    {
        try {
            $this->get('routing')->dispatch($this->get(Request::class))->send();
        } catch (Exception|Error $e) {
            $this->handle($e);
        }
    }

    public function down(): void
    {
        copy(Filesystem::join(__DIR__, 'templates', 'maintenance.php'), $this->file('maintenance', 'php'));
    }

    public function up(): void
    {
        unlink($this->file('maintenance', 'php'));
    }

    /**
     * Initializes whole application for you.
     */
    public static function init(string $directory, bool $terminal = true): self
    {
        $directory = Filesystem::parent($directory);
        $maintenance = $directory.DIRECTORY_SEPARATOR.'maintenance.php';

        if (file_exists($maintenance)) {
            require $maintenance;

            exit;
        }

        // --- Creating Application instance ---
        $application = new self($directory);

        // --- Obtaining request ---
        if (!$application->runsInTerminal()) {
            $application->add(Request::class, Request::capture()->injectApplication($application));

            $application->alias('request', Request::class);
        }

        // --- Loading default Lemon services ---
        $application->loadServices();

        // --- Loading Zests for services ---
        $application->loadZests();

        // --- Loading Error/Exception handlers ---
        $application->loadHandler();

        // --- Loading commands ---
        $application->loadCommands();

        /* --- The end ---
         * This function automaticaly boots our app at the end of file
         */
        register_shutdown_function(function () use ($application, $terminal) {
            /* --- Terminal ---
             * Once we run index.php from terminal via php index.php it will automaticaly start terminal
             * mode which will work instead of lemonade
             */
            if ($application->runsInTerminal()) {
                if ($terminal) {
                    $application->get('terminal')->run(array_slice($GLOBALS['argv'], 1));
                }

                return;
            }

            if (http_response_code() >= 500) {
                return;
            }

            $application->get(Router::class)->routes()->middleware(Csrf::class);

            $application->boot();
        });

        return $application;
    }
}
