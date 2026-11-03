<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServerRequest;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServerResource::collection(
            Server::query()->withCount('applications')->orderBy('name')->get()
        );
    }

    public function store(StoreServerRequest $request): JsonResponse
    {
        $server = Server::query()->create($request->validated());

        return new JsonResponse(['data' => ServerResource::make($server)->resolve()], 201);
    }

    public function update(StoreServerRequest $request, Server $server): ServerResource
    {
        $server->update($request->validated());

        return ServerResource::make($server->fresh());
    }

    public function destroy(Server $server): JsonResponse
    {
        $server->delete();

        return new JsonResponse(['message' => 'Server removed.']);
    }
}
