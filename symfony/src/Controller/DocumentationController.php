<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(host: '%app.host.samples%')]
final class DocumentationController extends AbstractController
{
    #[Route('/documentation', name: 'durable_documentation', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('documentation/index.html.twig');
    }
}
