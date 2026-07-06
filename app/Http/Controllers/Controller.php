<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Goriya API',
    description: "API REST de la plateforme Goriya : authentification, utilisateurs, entreprises, offres d'emploi, candidatures, portfolios, planning, IA (analyse de CV, matching, scoring), abonnements, et module d'administration."
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'Serveur API')]
abstract class Controller
{
    //
}
