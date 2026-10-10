<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Help\HelpCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Help (specification 2.11): how catto-mail works, explained without the source code. */
#[Route('/dashboard/operator/help')]
#[IsGranted('PLATFORM.HELP.VIEW')]
final class HelpController extends AbstractController
{
    #[Route('', name: 'dashboard_operator_help', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/help/index.html.twig', ['section' => 'help', 'groups' => HelpCatalog::GROUPS]);
    }

    #[Route('/{topic}', name: 'dashboard_operator_help_topic', methods: ['GET'], requirements: ['topic' => '[a-z-]+'])]
    public function topic(string $topic): Response
    {
        $title = HelpCatalog::title($topic) ?? throw new NotFoundHttpException('Not found.');
        $group = null;
        foreach (HelpCatalog::GROUPS as $g => $topics) {
            if (isset($topics[$topic])) {
                $group = $g;
            }
        }

        return $this->render("dashboard/help/topics/$topic.html.twig", [
            'section' => 'help', 'topic' => $topic, 'title' => $title, 'group' => $group, 'groups' => HelpCatalog::GROUPS]);
    }
}
