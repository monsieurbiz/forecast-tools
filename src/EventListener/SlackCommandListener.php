<?php

/*
 * This file is part of JoliCode's Forecast Tools project.
 *
 * (c) JoliCode <coucou@jolicode.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventListener;

use App\Converter\WordToNumberConverter;
use App\Entity\ForecastReminder;
use App\ForecastReminder\Builder;
use App\Repository\ForecastReminderRepository;
use App\StandupMeetingReminder\Handler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SlackCommandListener implements EventSubscriberInterface
{
    private $forecastReminderRepository;
    private $standupMeetingReminderHandler;
    private $wordToNumberConverter;

    public function __construct(ForecastReminderRepository $forecastReminderRepository, WordToNumberConverter $wordToNumberConverter, Handler $standupMeetingReminderHandler)
    {
        $this->forecastReminderRepository = $forecastReminderRepository;
        $this->wordToNumberConverter = $wordToNumberConverter;
        $this->standupMeetingReminderHandler = $standupMeetingReminderHandler;
    }

    public function onTerminate(TerminateEvent $event)
    {
        $request = $event->getRequest();

        if ('slack_command' === $request->attributes->get('_route')) {
            if ('/forecast' === $request->request->get('command')) {
                if ('help' === $request->request->get('text')) {
                    return $this->forecastHelp($request->request->get('response_url'));
                }
                $this->sendForecastReminders($request);
            } elseif ('/standup-reminder' === $request->request->get('command')) {
                $this->standupMeetingReminderHandler->handleRequest($request);

                // if list command, try to preload available projects so that they're in cache
                if ('list' === $request->request->get('text')) {
                    $this->standupMeetingReminderHandler->loadProjects($request->request->get('team_id'));
                }
            }
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    private function buildReminder(ForecastReminder $forecastReminder, ?string $text = ''): array
    {
        $startDate = $this->extractStartDateFromtext($text);
        $builder = new Builder($forecastReminder);
        $title = $builder->buildTitle($startDate);
        $message = $builder->buildMessage($startDate);

        return [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $title,
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $message,
                    ],
                ],
            ],
            'replace_original' => true,
        ];
    }

    private function extractStartDateFromtext(string $text): \DateTime
    {
        if ('' === $text) {
            $text = 'tomorrow';
        }

        $text = $this->wordToNumberConverter->convert($text);
        $sign = '';

        if (0 === strncmp($text, 'in ', 3)) {
            $text = '+' . substr($text, 3);
        }

        if (0 === substr_compare($text, ' ago', -4)) {
            $text = '-' . substr($text, 0, \strlen($text) - 4);
            $sign = '-';
        }

        $text = str_replace(' and ', ', ' . $sign, $text);
        $datetime = new \DateTime($text);

        if ($datetime && 0 === \DateTime::getLastErrors()['warning_count'] && 0 === \DateTime::getLastErrors()['error_count']) {
            return $datetime;
        }

        return new \DateTime('+1 day');
    }

    private function forecastHelp(string $responseUrl)
    {
        $message = <<<'EOT'
*Time parameters* display the Forecast for a specific date:

➡️ `/forecast tomorrow`
➡️ `/forecast yesterday`
➡️ `/forecast today`
➡️ `/forecast next wednesday`
➡️ `/forecast in one week and three days`
➡️ `/forecast 2 weeks ago`
EOT;

        return $this->sendMessage($responseUrl, [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $message,
                    ],
                ],
            ],
            'replace_original' => true,
        ]);
    }

    private function sendForecastReminders(Request $request)
    {
        $forecastReminders = $this->forecastReminderRepository->findByTeamId($request->request->get('team_id'));

        if (0 === \count($forecastReminders)) {
            $body = [
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => 'Your Slack team is not configured in Forecast tools, or the Slack reminder has not been enabled. Oh dear, why do you have our app installed? :upside_down_face:',
                        ],
                    ],
                ],
                'replace_original' => true,
            ];
            $this->sendMessage(
                $request->request->get('response_url'),
                $body
            );
        } else {
            foreach ($forecastReminders as $forecastReminder) {
                $body = $this->buildReminder($forecastReminder, $request->request->get('text'));
                $this->sendMessage(
                    $request->request->get('response_url'),
                    $body
                );
            }
        }
    }

    private function sendMessage(string $responseUrl, array $body)
    {
        $client = HttpClient::create();
        $client->request('POST', $responseUrl, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
        ]);
    }
}
