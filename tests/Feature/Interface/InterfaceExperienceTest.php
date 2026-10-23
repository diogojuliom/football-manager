<?php

namespace Tests\Feature\Interface;

use App\Application\Careers\CreateCareer;
use App\Domain\Career\Models\Career;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterfaceExperienceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->career = app(CreateCareer::class)->execute($this->owner, [
            'name' => 'Jornada de interface',
            'coach_name' => 'Alex Silva',
            'club_template_id' => ClubTemplate::firstOrFail()->id,
            'request_id' => (string) Str::uuid(),
        ]);
        $this->actingAs($this->owner);
    }

    public function test_dashboard_exposes_complete_management_navigation_and_progress_feedback(): void
    {
        $this->get(route('careers.show', $this->career))
            ->assertOk()
            ->assertSee('data-menu-toggle', false)
            ->assertSee('Central de progressão')
            ->assertSee('Próxima partida')
            ->assertSee('Avançar 1 dia')
            ->assertSee('Próximo acontecimento')
            ->assertSee('Condição do elenco')
            ->assertSee('Obra em andamento')
            ->assertSee('id="classificacao"', false)
            ->assertSee('id="calendario"', false)
            ->assertSee('id="estatisticas"', false)
            ->assertSee('id="historico"', false)
            ->assertSee('data-progression', false);
    }

    public function test_every_management_area_is_available_from_the_web_interface(): void
    {
        $pages = [
            route('careers.squad.edit', $this->career) => ['Elenco e táticas', 'data-loading-form'],
            route('careers.stadium.show', $this->career) => ['Obras e infraestrutura', 'Receita acumulada de bilheteria'],
            route('careers.tickets.index', $this->career) => ['Preço padrão', 'data-ticket-preview'],
            route('careers.finances.show', $this->career) => ['Histórico das movimentações', 'Resultado da temporada'],
            route('careers.friendlies.create', $this->career) => ['Jogar amistoso', 'IA contra IA'],
        ];

        foreach ($pages as $url => $content) {
            $response = $this->get($url)->assertOk()->assertSee('id="game-sidebar"', false);
            foreach ($content as $text) {
                $response->assertSee($text, false);
            }
        }
    }
}
