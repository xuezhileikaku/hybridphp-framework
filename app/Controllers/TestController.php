<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use Amp\Future;
use function Amp\async;

/**
 * TestController - Resource Controller
 */
class TestController
{
    /**
     * Display a listing of the resource
     */
    public function index(Request $request): Promise
    {
        return async(function () use ($request) {
            // TODO: Implement index logic
            return Response::json([
                'data' => [],
                'message' => 'Resource listing'
            ]);
        });
    }

    /**
     * Show the form for creating a new resource
     */
    public function create(Request $request): Promise
    {
        return async(function () use ($request) {
            // TODO: Implement create form logic
            return Response::json([
                'message' => 'Create form'
            ]);
        });
    }

    /**
     * Store a newly created resource
     */
    public function store(Request $request): Promise
    {
        return async(function () use ($request) {
            // TODO: Implement store logic
            return Response::json([
                'message' => 'Resource created'
            ], 201);
        });
    }

    /**
     * Display the specified resource
     */
    public function show(Request $request, string $id): Promise
    {
        return async(function () use ($request, $id) {
            // TODO: Implement show logic
            return Response::json([
                'id' => $id,
                'message' => 'Resource details'
            ]);
        });
    }

    /**
     * Show the form for editing the specified resource
     */
    public function edit(Request $request, string $id): Promise
    {
        return async(function () use ($request, $id) {
            // TODO: Implement edit form logic
            return Response::json([
                'id' => $id,
                'message' => 'Edit form'
            ]);
        });
    }

    /**
     * Update the specified resource
     */
    public function update(Request $request, string $id): Promise
    {
        return async(function () use ($request, $id) {
            // TODO: Implement update logic
            return Response::json([
                'id' => $id,
                'message' => 'Resource updated'
            ]);
        });
    }

    /**
     * Remove the specified resource
     */
    public function destroy(Request $request, string $id): Promise
    {
        return async(function () use ($request, $id) {
            // TODO: Implement destroy logic
            return Response::json([
                'id' => $id,
                'message' => 'Resource deleted'
            ]);
        });
    }
}