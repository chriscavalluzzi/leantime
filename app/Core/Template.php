<?php

namespace Leantime\Core;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\Compiler;
use Illuminate\View\Compilers\CompilerInterface;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\FileViewFinder;
use Illuminate\View\View;
use Illuminate\View\ViewFinderInterface;
use Leantime\Domain\Auth\Models\Roles;
use Leantime\Domain\Auth\Services\Auth as AuthService;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Template class - Template routing
 *
 * @package leantime
 * @subpackage core
 */
class Template
{
    use Eventhelpers;

    /**
     * @var array - vars that are set in the action
     */
    private array $vars = array();

    /**
     *
     * @var string|Frontcontroller
     */
    public string|Frontcontroller $frontcontroller = '';

    /**
     * @var string
     */
    private string $notifcation = '';

    /**
     * @var string
     */
    private string $notifcationType = '';

    /**
     * @var string
     */
    private string $hookContext = '';

    /**
     * @var string
     */
    public string $tmpError = '';

    /**
     * @var IncomingRequest|string
     */
    public string|IncomingRequest $incomingRequest = '';

    /**
     * @var language|string
     */
    public Language|string $language = '';

    /**
     * @var string
     */
    public string $template = '';

    /**
     * @var array
     */
    public array $picture = array(
        'calendar' => 'fa-calendar',
        'clients' => 'fa-people-group',
        'dashboard' => 'fa-th-large',
        'files' => 'fa-picture',
        'leads' => 'fa-signal',
        'messages' => 'fa-envelope',
        'projects' => 'fa-bar-chart',
        'setting' => 'fa-cogs',
        'tickets' => 'fa-pushpin',
        'timesheets' => 'fa-table',
        'users' => 'fa-people-group',
        'default' => 'fa-off',
    );

    /**
     * @var Theme
     */
    private Theme $theme;

    /**
     * @var \Illuminate\View\Factory
     */
    public Factory $viewFactory;
    private AppSettings $settings;
    private Environment $config;
    private AuthService $login;
    private Roles $roles;
    private CompilerInterface $bladeCompiler;


    /**
     * __construct - get instance of frontcontroller
     *
     * @param Theme           $theme
     * @param Language        $language
     * @param Frontcontroller $frontcontroller
     * @param IncomingRequest $incomingRequest
     * @param Environment     $config
     * @param AppSettings     $settings
     * @param AuthService     $login
     * @param Roles           $roles
     * @param Factory|null    $viewFactory
     * @param Compiler|null   $bladeCompiler
     * @throws BindingResolutionException
     * @throws \ReflectionException
     * @access public
     */
    public function __construct(
        Theme $theme,
        Language $language,
        Frontcontroller $frontcontroller,
        IncomingRequest $incomingRequest,
        Environment $config,
        AppSettings $settings,
        AuthService $login,
        Roles $roles,
        Factory $viewFactory = null,
        Compiler $bladeCompiler = null
    ) {
        $this->theme = $theme;
        $this->language = $language;
        $this->frontcontroller = $frontcontroller;
        $this->incomingRequest = $incomingRequest;
        $this->config = $config;
        $this->settings = $settings;
        $this->login = $login;
        $this->roles = $roles;

        if (! is_null($viewFactory) && ! is_null($bladeCompiler)) {
            $this->viewFactory = $viewFactory;
            $this->bladeCompiler = $bladeCompiler;
        } else {
            app()->call([$this, 'setupBlade']);
            $this->attachComposers();
            $this->setupDirectives();
            $this->setupGlobalVars();
        }
    }

    /**
     * Create View Factory capable of rendering PHP and Blade templates
     *
     * @param Application $app
     * @param Dispatcher  $eventDispatcher
     * @return void
     * @throws BindingResolutionException
     */
    public function setupBlade(
        Application $app,
        Dispatcher $eventDispatcher
    ): void {
        // ComponentTagCompiler Expects the Foundation\Application Implmentation, let's trick it and give it the container.
        $app->instance(\Illuminate\Contracts\Foundation\Application::class, $app::getInstance());

        // View/Component createBladeViewFromString method needs to access the view compiled path, expects it to be attached to config
        $this->config->set('view.compiled', APP_ROOT . '/cache/views');

        // Find Template Paths
        if (empty($_SESSION['template_paths']) || $this->config->debug) {
            $domainPaths = collect(glob(APP_ROOT . '/app/Domain/*'))
                ->mapWithKeys(fn ($path) => [
                    $basename = strtolower(basename($path)) => [
                        APP_ROOT . '/custom/Domain/' . $basename . '/Templates',
                        "$path/Templates",
                    ],
                ]);

            $pluginPaths = collect(glob(APP_ROOT . '/app/Plugins/*'))
                ->mapWithKeys(function ($path) use ($domainPaths) {
                    if ($domainPaths->has($basename = strtolower(basename($path)))) {
                        throw new Exception("Plugin $basename conflicts with domain");
                    }
                    return [$basename => "$path/Templates"];
                });

            $_SESSION['template_paths'] = $domainPaths
                ->merge($pluginPaths)
                ->merge(['global' => APP_ROOT . '/app/Views/Templates'])
                ->all();
        }

        // Setup Blade Compiler
        $app->singleton(
            CompilerInterface::class,
            function ($app) {
                $bladeCompiler = new BladeCompiler(
                    $app->make(Filesystem::class),
                    $this->config->get('view.compiled'),
                );

                $namespaces = array_keys($_SESSION['template_paths']);
                array_map(
                    [$bladeCompiler, 'anonymousComponentNamespace'],
                    array_map(fn ($namespace) => "$namespace::components", $namespaces),
                    $namespaces
                );

                return $bladeCompiler;
            }
        );
        $app->alias(CompilerInterface::class, 'blade.compiler');

        // Register Blade Engines
        $app->singleton(
            EngineResolver::class,
            function ($app) {
                $viewResolver = new EngineResolver();
                $viewResolver->register('blade', fn () => $app->make(CompilerEngine::class));
                $viewResolver->register('php', fn () => $app->make(PhpEngine::class));
                return $viewResolver;
            }
        );
        $app->alias(EngineResolver::class, 'view.engine.resolver');

        // Setup View Finder
        $app->singleton(
            ViewFinderInterface::class,
            function ($app) {
                $viewFinder = $app->make(FileViewFinder::class, ['paths' => []]);
                array_map([$viewFinder, 'addNamespace'], array_keys($_SESSION['template_paths']), array_values($_SESSION['template_paths']));
                return $viewFinder;
            }
        );
        $app->alias(ViewFinderInterface::class, 'view.finder');

        // Setup Events Dispatcher
        $app->bind(\Illuminate\Contracts\Events\Dispatcher::class, Dispatcher::class);

        // Setup View Factory
        $app->singleton(
            Factory::class,
            function ($app) {
                $viewFactory = $app->make(\Illuminate\View\Factory::class);
                array_map(fn ($ext) => $viewFactory->addExtension($ext, 'php'), ['inc.php', 'sub.php', 'tpl.php']);
                // reprioritize blade
                $viewFactory->addExtension('blade.php', 'blade');
                $viewFactory->setContainer($app);
                return $viewFactory;
            }
        );
        $app->alias(Factory::class, 'view');

        $this->bladeCompiler = $app->make(CompilerInterface::class);
        $this->viewFactory = $app->make(Factory::class);
    }

    /**
     * attachComposers - attach view composers
     *
     * @return void
     * @throws \ReflectionException
     */
    public function attachComposers(): void
    {
        if (empty($_SESSION['composers']) || $this->config->debug) {
            $customComposerClasses = collect(glob(APP_ROOT . '/custom/Views/Composers/*.php'))
                ->concat(glob(APP_ROOT . '/custom/Domain/*/Composers/*.php'));

            $appComposerClasses = collect(glob(APP_ROOT . '/app/Views/Composers/*.php'))
                ->concat(glob(APP_ROOT . '/app/Domain/*/Composers/*.php'));

            $testers = $customComposerClasses->map(fn ($path) => str_replace('/custom/', '/app/', $path));

            $filteredAppComposerClasses = $appComposerClasses->filter(fn ($composerClass) => ! $testers->contains($composerClass));

            $_SESSION['composers'] = $customComposerClasses
                ->concat($filteredAppComposerClasses)
                ->map(fn ($filepath) => Str::of($filepath)
                    ->replace([APP_ROOT . '/app/', APP_ROOT . '/custom/', '.php'], ['', '', ''])
                    ->replace('/', '\\')
                    ->start(app()->getNamespace())
                    ->toString())
                ->all();
        }

        foreach ($_SESSION['composers'] as $composerClass) {
            if (
                is_subclass_of($composerClass, Composer::class) &&
                ! (new ReflectionClass($composerClass))->isAbstract()
            ) {
                $this->viewFactory->composer($composerClass::$views, $composerClass);
            }
        }
    }

    /**
     * setupDirectives - setup blade directives
     *
     * @return void
     */
    public function setupDirectives(): void
    {
        $this->bladeCompiler->directive(
            'dispatchEvent',
            fn ($args) => "<?php \$tpl->dispatchTplEvent($args); ?>",
        );

        $this->bladeCompiler->directive(
            'dispatchFilter',
            fn ($args) => "<?php echo \$tpl->dispatchTplFilter($args); ?>",
        );

        $this->bladeCompiler->directive(
            'spaceless',
            fn ($args) => "<?php ob_start(); ?>",
        );

        $this->bladeCompiler->directive(
            'endspaceless',
            fn ($args) => "<?php echo preg_replace('/>\\s+</', '><', ob_get_clean()); ?>",
        );

        $this->bladeCompiler->directive(
            'formatDate',
            fn ($args) => "<?php echo \$tpl->getFormattedDateString($args); ?>",
        );

        $this->bladeCompiler->directive(
            'formatTime',
            fn ($args) => "<?php echo \$tpl->getFormattedTimeString($args); ?>",
        );
    }

    /**
     * setupGlobalVars - setup global vars
     *
     * @return void
     */
    public function setupGlobalVars(): void
    {
        $this->viewFactory->share([
            'frontController' => $this->frontcontroller,
            'config' => $this->config,
            /** @todo remove settings after renaming all uses to appSettings */
            'settings' => $this->settings,
            'appSettings' => $this->settings,
            'login' => $this->login,
            'roles' => $this->roles,
            'language' => $this->language,
        ]);
    }

    /**
     * assign - assign variables in the action for template
     *
     * @param string $name  Name of variable
     * @param mixed  $value Value of variable
     * @return void
     */
    public function assign(string $name, mixed $value): void
    {
        $value = self::dispatch_filter("var.$name", $value);

        $this->vars[$name] = $value;
    }

    /**
     * setNotification - assign errors to the template
     *
     * @param string $msg
     * @param string $type
     * @param string $event_id as a string for further identification
     * @return void
     */
    public function setNotification(string $msg, string $type, string $event_id = ''): void
    {
        $_SESSION['notification'] = $msg;
        $_SESSION['notifcationType'] = $type;
        $_SESSION['event_id'] = $event_id;
    }

    /**
     * getTemplatePath - Find template in custom and src directories
     *
     * @access public
     * @param string $namespace The namespace the template is for.
     * @param string $path      The path to the template.
     * @return string Full template path or false if file does not exist
     * @throws Exception If template not found.
     */
    public function getTemplatePath(string $namespace, string $path): string
    {
        if ($namespace == '' || $path == '') {
            throw new Exception("Both namespace and path must be provided");
        }

        $namespace = strtolower($namespace);
        $fullpath = self::dispatch_filter(
            "template_path__{$namespace}_{$path}",
            "$namespace::$path",
            [
                'namespace' => $namespace,
                'path' => $path,
            ]
        );

        if ($this->viewFactory->exists($fullpath)) {
            return $fullpath;
        }

        throw new Exception("Template $fullpath not found");
    }

    /**
     * gives HTMX response
     *
     * @param string $view     The blade view path.
     * @param string $fragment The fragment key.
     * @return never
     */
    public function displayFragment(string $view, string $fragment = ''): never
    {
        $this->viewFactory->share(['tpl' => $this]);
        echo $this->viewFactory
            ->make($view, $this->vars)
            ->fragmentIf(! empty($fragment), $fragment);
        exit;
    }

    /**
     * display - display template from folder template including main layout wrapper
     *
     * @access public
     * @param string $template
     * @param string $layout
     * @return void
     * @throws Exception
     */
    public function display(string $template, string $layout = "app"): void
    {
        $template = self::dispatch_filter('template', $template);
        $template = self::dispatch_filter("template.$template", $template);

        $this->template = $template;

        $layout = $this->confirmLayoutName($layout, $template);

        $action = Frontcontroller::getActionName($template);
        $module = Frontcontroller::getModuleName($template);

        $loadFile = $this->getTemplatePath($module, $action);

        $this->hookContext = "tpl.$module.$action";
        $this->viewFactory->share(['tpl' => $this]);

        /** @var View $this */
        $view = $this->viewFactory->make($loadFile);

        /** @todo this can be reduced to just the 'if' code after removal of php template support */
        if ($view->getEngine() instanceof CompilerEngine) {
            $view->with(array_merge(
                $this->vars,
                ['layout' => $layout]
            ));
        } else {
            $view = $this->viewFactory->make($layout, array_merge(
                $this->vars,
                ['module' => strtolower($module), 'action' => $action]
            ));
        }

        $content = $view->render();
        $content = self::dispatch_filter('content', $content);
        $content = self::dispatch_filter("content.$template", $content);

        echo $content;
    }

    /**
     * @param $layoutName
     * @param $template
     * @return bool|string
     * @throws Exception
     */
    /**
     * @param $layoutName
     * @param $template
     * @return bool|string
     * @throws Exception
     */
    protected function confirmLayoutName($layoutName, $template): bool|string
    {
        $layout = htmlspecialchars($layoutName);
        $layout = self::dispatch_filter("layout", $layout);
        $layout = self::dispatch_filter("layout.$template", $layout);

        $layout = $this->getTemplatePath('global', "layouts.$layout");

        return $layout;
    }

    /**
     * displayJson - returns json data
     *
     * @access public
     * @param  $jsonContent
     * @return void
     */
    public function displayJson($jsonContent): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($jsonContent !== false) {
            echo $jsonContent;
        } else {
            echo json_encode(['error' => 'Invalid Json']);
        }
    }

    /**
     * display - display only the template from the template folder without a wrapper
     *
     * @access public
     * @param  $template
     * @return void
     * @throws Exception
     */
    public function displayPartial($template): void
    {
        $this->display($template, 'blank');
    }

    /**
     * get - get assigned values
     *
     * @access public
     * @param string $name
     * @return array
     */
    public function get(string $name): mixed
    {
        if (!isset($this->vars[$name])) {
            return null;
        }

        return $this->vars[$name];
    }

    /**
     * getNotification - pulls notification from the current session
     *
     * @access public
     * @return array
     */
    public function getNotification(): array
    {
        if (isset($_SESSION['notifcationType']) && isset($_SESSION['notification'])) {
            $event_id = $_SESSION['event_id'] ?? '';
            return array('type' => $_SESSION['notifcationType'], 'msg' => $_SESSION['notification'], 'event_id' => $event_id);
        } else {
            return array('type' => "", 'msg' => "", 'event_id' => "");
        }
    }

    /**
     * displaySubmodule - display a submodule for a given module
     *
     * @access public
     * @param string $alias
     * @return void
     * @throws Exception
     */
    public function displaySubmodule(string $alias): void
    {
        if (! str_contains($alias, '-')) {
            throw new Exception("Submodule alias must be in the format module-submodule");
        }

        [$module, $submodule] = explode("-", $alias);

        $relative_path = $this->getTemplatePath($module, "submodules.$submodule");

        echo $this->viewFactory->make($relative_path, array_merge($this->vars, ['tpl' => $this]))->render();
    }

    /**
     * displayNotification - display notification
     *
     * @access public
     * @return string
     * @throws BindingResolutionException
     */
    public function displayNotification(): string
    {
        $notification = '';
        $note = $this->getNotification();
        $language = $this->language;
        $message_id = $note['msg'];

        $message = self::dispatch_filter(
            'message',
            $language->__($message_id),
            $note
        );
        $message = self::dispatch_filter(
            "message_{$message_id}",
            $message,
            $note
        );

        if (!empty($note) && $note['msg'] != '' && $note['type'] != '') {
            $notification = app('blade.compiler')::render(
                '<script type="text/javascript">jQuery.growl({message: "{{ $message }}", style: "{{ $style }}"});</script>',
                [
                    'message' => $message,
                    'style' => $note['type'],
                ],
                deleteCachedView: true
            );

            self::dispatch_event("notification_displayed", $note);

            $_SESSION['notification'] = "";
            $_SESSION['notificationType'] = "";
            $_SESSION['event_id'] = "";
        }

        return $notification;
    }

    /**
     * displayInlineNotification - display notification
     *
     * @access public
     * @return string
     * @throws BindingResolutionException
     */
    public function displayInlineNotification(): string
    {
        $notification = '';
        $note = $this->getNotification();
        $language = $this->language;
        $message_id = $note['msg'];

        $message = self::dispatch_filter(
            'message',
            $language->__($message_id),
            $note
        );
        $message = self::dispatch_filter(
            "message_{$message_id}",
            $message,
            $note
        );

        if (!empty($note) && $note['msg'] != '' && $note['type'] != '') {
            $notification = app('blade.compiler')::render(
                '<div class="inputwrapper login-alert login-{{ $type }}" style="position: relative;">
                    <div class="alert alert-{{ $type }}" style="padding:15px;" >
                        <strong>{!! $message !!}</strong>
                    </div>
                </div>',
                [
                    'type' => $note['type'],
                    'message' => $message,
                ],
                deleteCachedView: true
            );

            self::dispatch_event("notification_displayed", $note);

            $_SESSION['notification'] = "";
            $_SESSION['notificationType'] = "";
            $_SESSION['event_id'] = "";
        }

        return $notification;
    }

    /**
     * redirect - redirect to a given url
     *
     * @param  string $url
     * @return void
     */
    public function redirect(string $url): void
    {
        header("Location:" . trim($url));
        exit();
    }

    /**
     * getSubdomain - get subdomain from url
     *
     * @return string
     */
    public function getSubdomain(): string
    {
        preg_match('/(?:http[s]*\:\/\/)*(.*?)\.(?=[^\/]*\..{2,5})/i', $_SERVER['HTTP_HOST'], $match);

        $domain = $_SERVER['HTTP_HOST'];
        $tmp = explode('.', $domain); // split into parts
        $subdomain = $tmp[0];

        return $subdomain;
    }

    /**
     * __ - returns a language specific string. wraps language class method
     *
     * @param  string $index
     * @return string
     */
    public function __(string $index): string
    {
        return $this->language->__($index);
    }

    /**
     * e - echos and escapes content
     *
     * @param string|null $content
     * @return void
     */
    public function e(?string $content): void
    {
        $content = $this->convertRelativePaths($content);
        $escaped = $this->escape($content);

        echo $escaped;
    }

    /**
     * escape - escapes content
     *
     * @param string|null $content
     * @return string
     */
    public function escape(?string $content): string
    {
        if (!is_null($content)) {
            $content = $this->convertRelativePaths($content);
            return htmlentities($content);
        }

        return '';
    }

    /**
     * escapeMinimal - escapes content
     *
     * @param string|null $content
     * @return string
     */
    public function escapeMinimal(?string $content): string
    {
        $content = $this->convertRelativePaths($content);
        $config = array(
            'safe' => 1,
            'style_pass' => 1,
            'cdata' => 1,
            'comment' => 1,
            'deny_attribute' => '* -href -style',
            'keep_bad' => 0,
        );

        if (!is_null($content)) {
            return htmLawed($content, array(
                'comments' => 0,
                'cdata' => 0,
                'deny_attribute' => 'on*',
                'elements' => '* -applet -canvas -embed -object -script',
                'schemes' => 'href: aim, feed, file, ftp, gopher, http, https, irc, mailto, news, nntp, sftp, ssh, tel, telnet; style: !; *:file, http, https',
            ));
        }

        return '';
    }

    /**
     * getFormattedDateString - returns a language specific formatted date string. wraps language class method
     *
     * @access public
     * @param
     * @return string
     */
    public function getFormattedDateString($date): string
    {
        if ($date == null) {
            return '';
        }

        return $this->language->getFormattedDateString($date);
    }

    /**
     * getFormattedTimeString - returns a language specific formatted time string. wraps language class method
     *
     * @access public
     * @param string $date
     * @return string
     */
    public function getFormattedTimeString(string $date): string
    {
        return $this->language->getFormattedTimeString($date);
    }

    /**
     * getFormattedDateTimeString - returns a language specific formatted date and time string. wraps language class method
     *
     * @access public
     * @param string $dateTime
     * @return string
     */
    public function get24HourTimestring(string $dateTime): string
    {
        return $this->language->get24HourTimestring($dateTime);
    }

    /**
     * truncate - truncate text
     *
     * @see https://stackoverflow.com/questions/1193500/truncate-text-containing-html-ignoring-tags
     * @author Søren Løvborg <https://stackoverflow.com/users/136796/s%c3%b8ren-l%c3%b8vborg>
     * @access public
     * @param string $html
     * @param int    $maxLength
     * @param string $ending
     * @param bool   $exact
     * @param bool   $considerHtml
     * @return string
     */
    public function truncate(string $html, int $maxLength = 100, string $ending = '(...)', bool $exact = true, bool $considerHtml = false): string
    {
        $printedLength = 0;
        $position = 0;
        $tags = array();
        $isUtf8 = true;
        $truncate = "";
        $html = $this->convertRelativePaths($html);
        // For UTF-8, we need to count multibyte sequences as one character.
        $re = $isUtf8 ? '{</?([a-z]+)[^>]*>|&#?[a-zA-Z0-9]+;|[\x80-\xFF][\x80-\xBF]*}' : '{</?([a-z]+)[^>]*>|&#?[a-zA-Z0-9]+;}';

        while ($printedLength < $maxLength && preg_match($re, $html, $match, PREG_OFFSET_CAPTURE, $position)) {
            list($tag, $tagPosition) = $match[0];

            // Print text leading up to the tag.
            $str = substr($html, $position, $tagPosition - $position);
            if ($printedLength + strlen($str) > $maxLength) {
                $truncate .= substr($str, 0, $maxLength - $printedLength);
                $printedLength = $maxLength;
                break;
            }

            $truncate .= $str;
            $printedLength += strlen($str);
            if ($printedLength >= $maxLength) {
                break;
            }

            if ($tag[0] == '&' || ord($tag) >= 0x80) {
                // Pass the entity or UTF-8 multibyte sequence through unchanged.
                $truncate .= $tag;
                $printedLength++;
            } else {
                // Handle the tag.
                $tagName = $match[1][0];
                if ($tag[1] == '/') {
                    // This is a closing tag.

                    $openingTag = array_pop($tags);
                    assert($openingTag == $tagName); // check that tags are properly nested.

                    $truncate .= $tag;
                } elseif ($tag[strlen($tag) - 2] == '/') {
                    // Self-closing tag.
                    $truncate .= $tag;
                } else {
                    // Opening tag.
                    $truncate .= $tag;
                    $tags[] = $tagName;
                }
            }

            // Continue after the tag.
            $position = $tagPosition + strlen($tag);
        }

        // Print any remaining text.
        if ($printedLength < $maxLength && $position < strlen($html)) {
            $truncate .= sprintf(substr($html, $position, $maxLength - $printedLength));
        }

        // Close any open tags.
        while (!empty($tags)) {
            $truncate .= sprintf('</%s>', array_pop($tags));
        }

        if (strlen($truncate) >= $maxLength) {
            $truncate .= $ending;
        }

        return $truncate;
    }

    /**
     * convertRelativePaths - convert relative paths to absolute paths
     *
     * @access public
     * @param string|null $text
     * @return string|null
     */
    public function convertRelativePaths(?string $text): ?string
    {
        if (is_null($text)) {
            return $text;
        }

        $base = BASE_URL;

        // base url needs trailing /
        $base = rtrim($base, "/") . "/";

        // Replace links
        $text = preg_replace(
            '/<a([^>]*) href="([^http|ftp|https|mailto|#][^"]*)"/',
            "<a\${1} href=\"$base\${2}\"",
            $text
        );

        // Replace images
        $text = preg_replace(
            '/<img([^>]*) src="([^http|ftp|https][^"]*)"/',
            "<img\${1} src=\"$base\${2}\"",
            $text
        );

        // Done
        return $text;
    }

    /**
     * getModulePicture - get module picture
     *
     * @access public
     * @return string
     * @throws BindingResolutionException
     */
    public function getModulePicture(): string
    {
        $module = frontcontroller::getModuleName($this->template);

        $picture = $this->picture['default'];
        if (isset($this->picture[$module])) {
            $picture = $this->picture[$module];
        }

        return $picture;
    }

    /**
     * displayLink - display link
     *
     * @access public
     * @param string     $module
     * @param string     $name
     * @param array|null $params
     * @param array|null $attribute
     * @return false|string
     */
    public function displayLink(string $module, string $name, array $params = null, array $attribute = null): false|string
    {

        $mod = explode('.', $module);

        if (is_array($mod) === true && count($mod) == 2) {
            $action = $mod[1];
            $module = $mod[0];

            $mod = $module . '/class.' . $action . '.php';
        } else {
            $mod = array();
            return false;
        }

        $returnLink = false;

        $url = "/" . $module . "/" . $action . "/";

        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $url .= $value . "/";
            }
        }

        $attr = '';

        if ($attribute != null) {
            foreach ($attribute as $key => $value) {
                $attr .= $key . " = '" . $value . "' ";
            }
        }

        $returnLink = "<a href='" . BASE_URL . "" . $url . "' " . $attr . ">" . $name . "</a>";

        return $returnLink;
    }

    /**
     * patchDownloadUrlToFilenameOrAwsUrl - Replace all local download.php references in <img src=""> tags
     * by either local filenames or AWS URLs that can be accesse without being authenticated
     *
     * Note: This patch is required by the PDF generating engine as it retrieves URL data without being
     * authenticated
     *
     * @access public
     * @param  string $textHtml HTML text, potentially containing <img srv="https://local.domain/download.php?xxxx"> tags
     * @return string  HTML text with the https://local.domain/download.php?xxxx replaced by either full qualified
     *                 local filenames or AWS URLs
     */

    public function patchDownloadUrlToFilenameOrAwsUrl(string $textHtml): string
    {
        $patchedTextHtml = $this->convertRelativePaths($textHtml);

        // TO DO: Replace local download.php
        $patchedTextHtml = $patchedTextHtml;

        return $patchedTextHtml;
    }

    /**
     * @param string $hookName
     * @param mixed  $payload
     */
    public function dispatchTplEvent(string $hookName, mixed $payload = null): void
    {
        $this->dispatchTplHook('event', $hookName, $payload);
    }

    /**
     * @param string $hookName
     * @param mixed  $payload
     * @param array  $available_params
     *
     * @return mixed
     */
    public function dispatchTplFilter(string $hookName, mixed $payload, array $available_params = []): mixed
    {

        return $this->dispatchTplHook('filter', $hookName, $payload, $available_params);
    }

    /**
     * @param string $type
     * @param string $hookName
     * @param array  $payload
     * @param array  $available_params
     *
     * @return null|mixed
     */
    private function dispatchTplHook(string $type, string $hookName, mixed $payload, array $available_params = []): mixed
    {
        if (
            !is_string($type) || !in_array($type, ['event', 'filter'])
        ) {
            return null;
        }

        if ($type == 'filter') {
            return self::dispatch_filter($hookName, $payload, $available_params, $this->hookContext);
        }

        self::dispatch_event($hookName, $payload, $this->hookContext);

        return null;
    }
}
