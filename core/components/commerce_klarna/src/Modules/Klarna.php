<?php
namespace modmore\Commerce_Klarna\Modules;

use modmore\Commerce\Admin\Configuration\About\ComposerPackages;
use modmore\Commerce\Admin\Sections\SimpleSection;
use modmore\Commerce\Events\Admin\PageEvent;
use modmore\Commerce\Events\Gateways;
use modmore\Commerce\Modules\BaseModule;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Loader\ChainLoader;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class Klarna extends BaseModule {

    public function getName()
    {
        $this->adapter->loadLexicon('commerce_klarna:default');
        return $this->adapter->lexicon('commerce_klarna');
    }

    public function getAuthor()
    {
        return 'modmore';
    }

    public function getDescription()
    {
        return $this->adapter->lexicon('commerce_klarna.description');
    }

    public function initialize(EventDispatcher $dispatcher)
    {
        // Load our lexicon
        $this->adapter->loadLexicon('commerce_klarna:default');

        $dispatcher->addListener(\Commerce::EVENT_GET_PAYMENT_GATEWAYS, [$this, 'addGateway']);

        // Add the xPDO package, so Commerce can detect the derivative classes
        $root = dirname(__DIR__, 2);
        $path = $root . '/model/';
        $this->adapter->loadPackage('commerce_klarna', $path);

        // Add template path to twig - 1.1+ way
        /** @var ChainLoader $loader */
        $root = dirname(__DIR__, 2);
        $this->commerce->view()->addTemplatesPath($root . '/templates/');

        // Add composer libraries to the about section (v0.12+)
        $dispatcher->addListener(\Commerce::EVENT_DASHBOARD_LOAD_ABOUT, [$this, 'addLibrariesToAbout']);
    }

    public function getModuleConfiguration(\comModule $module)
    {
        $fields = [];

        return $fields;
    }

    public function addGateway(Gateways $event)
    {
        $event->addGateway(\modmore\Commerce_Klarna\Gateways\Klarna::class, $this->adapter->lexicon('commerce_klarna.gateway'));
    }

    public function addLibrariesToAbout(PageEvent $event)
    {
        $lockFile = dirname(__DIR__, 2) . '/composer.lock';
        if (file_exists($lockFile)) {
            $section = new SimpleSection($this->commerce);
            $section->addWidget(new ComposerPackages($this->commerce, [
                'lockFile' => $lockFile,
                'heading' => $this->adapter->lexicon('commerce.about.open_source_libraries') . ' - ' . $this->adapter->lexicon('commerce_klarna'),
                'introduction' => '', // Could add information about how libraries are used, if you'd like
            ]));

            $about = $event->getPage();
            $about->addSection($section);
        }
    }
}
