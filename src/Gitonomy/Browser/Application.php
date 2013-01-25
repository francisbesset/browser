<?php

namespace Gitonomy\Browser;

use Symfony\Component\HttpFoundation\Request;

use Silex\Application as BaseApplication;
use Silex\Provider\FormServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Silex\Provider\TranslationServiceProvider;

use Gitonomy\Git\Repository;

use Gitonomy\Browser\Twig\GitExtension;
use Gitonomy\Browser\Routing\GitUrlGenerator;
use Gitonomy\Browser\Utils\RepositoriesFinder;

class Application extends BaseApplication
{
    /**
     * Constructor.
     *
     * @param string $configFile The config file to load.
     * @param array  $extraParam An array to overide params in configFile (usefull for test)
     */
    public function __construct($configFile, array $extraParam = array())
    {
        parent::__construct($extraParam);

        $gitonomy = $this;

        if (!file_exists($configFile)) {
            throw new \RuntimeException(sprintf('Can not find config file: "%s"', $configFile));
        }
        require $configFile;

        if (!isset($this['repositories'])) {
            throw new \RuntimeException(sprintf('You should declare some repositories in the config file: "%s"', $configFile));
        } elseif (is_string($this['repositories'])) {
            $repoFinder = new RepositoriesFinder();
            $this['repositories'] = $repoFinder->getRepositories($this['repositories']);
        } elseif ($this['repositories'] instanceof Repository) {
            $this['repositories'] = array(basename($this['repositories']->getPath()) => $this['repositories']);
        } elseif (!is_array($this['repositories'])) {
            throw new \RuntimeException(sprintf('"$gitonomy" should be a array of Repository or a string in "%s"', $configFile));
        }

        // urlgen
        $this->register(new UrlGeneratorServiceProvider());

        // translator
        $this->register(new TranslationServiceProvider(), array('locale_fallback' => 'en'));
        // form
        $this->register(new FormServiceProvider());

        // twig
        $this->register(new TwigServiceProvider(), array(
            'twig.path' => __DIR__.'/Resources/views',
            'debug'     => $this['debug'],
        ));

        $urlGenerator = new GitUrlGenerator($this['url_generator'], $this['repositories']);

        $this['twig']->addExtension(new GitExtension($urlGenerator, array('git/default_theme.html.twig')));

        $this->registerActions();
    }

    public function registerActions()
    {
        /**
         * Main page, showing all repositories.
         */
        $this->get('/', function (Application $app) {
            return $app['twig']->render('repository_list.html.twig');
        })->bind('repositories');

        /**
         * Landing page of a repository.
         */
        $this->get('/{name}', function (Application $app, $name) {
            if (!isset($app['repositories'][$name])) {
                $app->abort(404, "Repository $name does not exist");
            }

            return $app['twig']->render('log.html.twig', array(
                'name'       => $name,
                'repository' => $app['repositories'][$name]
            ));
        })->bind('repository');

        /**
         * Ajax Log entries
         */
        $this->get('/{name}/log-ajax', function (Request $request, Application $app, $name) {
            if (!isset($app['repositories'][$name])) {
                $app->abort(404, "Repository $name does not exist");
            }

            $repository = $app['repositories'][$name];

            if ($reference = $request->query->get('reference')) {
                $log = $repository->getReferences()->get($reference)->getLog();
            } else {
                $log = $repository->getLog();
            }

            if (null !== ($offset = $request->query->get('offset'))) {
                $log->setOffset($offset);
            }

            if (null !== ($limit = $request->query->get('limit'))) {
                $log->setLimit($limit);
            }

            $log = $repository->getLog()->setOffset($offset)->setLimit($limit);

            return $app['twig']->render('log_ajax.html.twig', array(
                'name'       => $name,
                'log'        => $log
            ));
        })->bind('log_ajax');

        /**
         * Commit page
         */
        $this->get('/{name}/commit/{hash}', function (Application $app, $name, $hash) {
            if (!isset($app['repositories'][$name])) {
                $app->abort(404, "Repository $name does not exist");
            }

            return $app['twig']->render('commit.html.twig', array(
                'name'   => $name,
                'commit' => $app['repositories'][$name]->getCommit($hash),
            ));
        })->bind('commit');

        /**
         * Reference page
         */
        $this->get('/{name}/{fullname}', function (Application $app, $name, $fullname) {
            if (!isset($app['repositories'][$name])) {
                $app->abort(404, "Repository $name does not exist");
            }

            return $app['twig']->render('reference.html.twig', array(
                'name'      => $name,
                'reference' => $app['repositories'][$name]->getReferences()->get($fullname),
            ));
        })->bind('reference')->assert('fullname', 'refs\\/.*');

        /**
         * Delete a reference
         */
        $this->post('/{name}/admin/delete-ref/{fullname}', function (Application $app, $name, $fullname) {
            if (!isset($app['repositories'][$name])) {
                $app->abort(404, "Repository $name does not exist");
            }

            $app['repositories'][$name]->getReferences()->get($fullname)->delete();

            return $app->redirect($app['url_generator']->generate('repository', array('name' => $name)));
        })->bind('reference_delete')->assert('fullname', 'refs\\/.*');
    }
}
