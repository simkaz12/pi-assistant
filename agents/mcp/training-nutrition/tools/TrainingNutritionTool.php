<?php

namespace App\Mcp\Tools;

use App\Http\Requests\TrainingNutrition\TrainingNutritionRequest;
use App\Models\User;
use App\Modules\TrainingNutrition\Services\TrainingNutritionException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

abstract class TrainingNutritionTool extends Tool
{
    protected string $requestClass;

    public function handle(Request $request): Response
    {
        try {
            $form = $this->validatedRequest($request);

            return Response::text(json_encode($this->run($form->validated(), $form->owner()), JSON_THROW_ON_ERROR));
        } catch (ValidationException $e) {
            return Response::error(json_encode([
                'code' => 'invalid_input',
                'message' => $e->validator->errors()->first(),
            ], JSON_THROW_ON_ERROR));
        } catch (AuthorizationException $e) {
            return Response::error(json_encode([
                'code' => 'unavailable',
                'message' => 'Training owner is not configured.',
            ], JSON_THROW_ON_ERROR));
        } catch (ModelNotFoundException $e) {
            return Response::error(json_encode([
                'code' => 'not_found',
                'message' => 'Record not found.',
            ], JSON_THROW_ON_ERROR));
        } catch (TrainingNutritionException $e) {
            return Response::error(json_encode([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            report($e);

            return Response::error(json_encode([
                'code' => 'unavailable',
                'message' => 'Training records unavailable.',
            ], JSON_THROW_ON_ERROR));
        }
    }

    private function validatedRequest(Request $request): TrainingNutritionRequest
    {
        $form = $this->requestClass::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($request->all(), JSON_THROW_ON_ERROR),
        );
        $form->attributes->replace(request()->attributes->all());
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->validateResolved();

        return $form;
    }

    abstract protected function run(array $input, User $owner): array;
}
