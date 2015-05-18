<?php namespace OpenCFP\Provider; 

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use OpenCFP\Application\Speakers;
use OpenCFP\Domain\CallForProposal;
use OpenCFP\Http\API\TalkController;
use OpenCFP\Http\OAuth\AuthorizationController;
use OpenCFP\Http\OAuth\ClientRegistrationController;
use OpenCFP\Infrastructure\Auth\SentryIdentityProvider;
use OpenCFP\Infrastructure\Auth\UhhhmIdentityProvider;
use OpenCFP\Infrastructure\Crypto\PseudoRandomStringGenerator;
use OpenCFP\Infrastructure\OAuth\AccessTokenStorage;
use OpenCFP\Infrastructure\OAuth\AuthCodeStorage;
use OpenCFP\Infrastructure\OAuth\ClientStorage;
use OpenCFP\Infrastructure\OAuth\RefreshTokenStorage;
use OpenCFP\Infrastructure\OAuth\ScopeStorage;
use OpenCFP\Infrastructure\OAuth\SessionStorage;
use OpenCFP\Infrastructure\Persistence\SpotSpeakerRepository;
use OpenCFP\Infrastructure\Persistence\SpotTalkRepository;
use RandomLib\Factory;
use Silex\Application;
use Silex\ServiceProviderInterface;

class ApplicationServiceProvider implements ServiceProviderInterface
{

    /**
     * {@inheritdoc}
     */
    public function register(Application $app)
    {
        $this->bindApplicationServices($app);
        $this->bindControllersAsServices($app);
    }

    /**
     * {@inheritdoc}
     */
    public function boot(Application $app)
    {
    }

    /**
     * @param Application $app
     */
    protected function bindApplicationServices(Application $app)
    {
        $app['application.speakers'] = $app->share(
            function ($app) {
                $userMapper = $app['spot']->mapper('OpenCFP\Domain\Entity\User');
                $talkMapper = $app['spot']->mapper('OpenCFP\Domain\Entity\Talk');
                $speakerRepository = new SpotSpeakerRepository($userMapper);

                return new Speakers(
                    new CallForProposal(new \DateTime($app->config('application.enddate'))),
                    new SentryIdentityProvider($app['sentry'], $speakerRepository),
                    $speakerRepository,
                    new SpotTalkRepository($talkMapper),
                    $app['dispatcher']
                );
            }
        );

        $app['security.random'] = $app->share(function($app) {
            return new PseudoRandomStringGenerator(new Factory());
        });

        $app['application.speakers.api'] = $app->share(
            function ($app) {
                $userMapper = $app['spot']->mapper('OpenCFP\Domain\Entity\User');
                $talkMapper = $app['spot']->mapper('OpenCFP\Domain\Entity\Talk');
                $speakerRepository = new SpotSpeakerRepository($userMapper);

                return new Speakers(
                    new CallForProposal(new \DateTime($app->config('application.enddate'))),
                    new UhhhmIdentityProvider($app['request'], $speakerRepository),
                    $speakerRepository,
                    new SpotTalkRepository($talkMapper),
                    $app['dispatcher']
                );
            }
        );
    }

    private function bindControllersAsServices($app)
    {
        $app['controller.api.talk'] = $app->share(function ($app) {
            return new TalkController($app['application.speakers.api']);
        });

        $app['controller.oauth.authorization'] = $app->share(function($app) {
            $server = new AuthorizationServer();

            $server->setSessionStorage(new SessionStorage());
            $server->setAccessTokenStorage(new AccessTokenStorage());
            $server->setRefreshTokenStorage(new RefreshTokenStorage());
            $server->setClientStorage(new ClientStorage());
            $server->setScopeStorage(new ScopeStorage());
            $server->setAuthCodeStorage(new AuthCodeStorage());

            $server->addGrantType(new AuthCodeGrant);
            $server->addGrantType(new RefreshTokenGrant);

            return new AuthorizationController($server);
        });

        $app['controller.oauth.clients'] = $app->share(function($app) {
            return new ClientRegistrationController(
                $app['spot']->mapper('OpenCFP\Domain\OAuth\Client'),
                $app['spot']->mapper('OpenCFP\Domain\OAuth\Endpoint'),
                $app['security.random']
            );
        });
    }
}