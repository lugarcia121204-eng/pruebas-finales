<?php

namespace Database\Seeders;

use App\Models\Rubric;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NationalRubricSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            if (Rubric::forStage(Rubric::NATIONAL)->active()->exists()) {
                return;
            }

            $criteria = [
                ['¿Qué nivel de relación tiene la evidencia presentada con las actividades académicas del docente?',
                    'Está directamente relacionada con sus actividades académicas.',
                    'Está parcialmente relacionada con sus actividades académicas.',
                    'No tiene relación con las actividades académicas.'],
                ['¿Qué información proporciona el documento para comprobar la actividad realizada?',
                    'Información completa y verificable.',
                    'Información parcial que requiere aclaraciones.',
                    'Información insuficiente para comprobar la actividad.'],
                ['¿Cómo se acredita la participación del docente en la actividad presentada?',
                    'Mediante una constancia o documento oficial.',
                    'Mediante evidencia complementaria que requiere validación.',
                    'No se acredita su participación.'],
                ['¿Cuál es el nivel de cumplimiento de los requisitos establecidos para la evidencia?',
                    'Cumple con todos los requisitos aplicables.',
                    'Cumple parcialmente con los requisitos.',
                    'No cumple con los requisitos establecidos.'],
                ['¿Qué grado de correspondencia existe entre la evidencia y el indicador de evaluación seleccionado?',
                    'Corresponde completamente al indicador.',
                    'Corresponde parcialmente al indicador.',
                    'No corresponde al indicador seleccionado.'],
                ['¿Cómo se verifica la autenticidad de la documentación presentada?',
                    'Contiene elementos oficiales que permiten verificar su autenticidad.',
                    'Requiere una validación adicional.',
                    'No presenta elementos suficientes para verificar su autenticidad.'],
                ['¿Qué nivel de contribución académica demuestra la actividad documentada?',
                    'Demuestra una contribución académica claramente identificable.',
                    'Demuestra una contribución académica limitada o poco documentada.',
                    'No permite identificar una contribución académica.'],
                ['¿Qué tan suficiente es la evidencia para respaldar la puntuación solicitada?',
                    'Respalda completamente la puntuación solicitada.',
                    'Respalda parcialmente la puntuación solicitada.',
                    'No respalda la puntuación solicitada.'],
                ['¿Cuál es el resultado de la revisión de consistencia de la información presentada?',
                    'La información es consistente y coincide con los datos registrados.',
                    'Presenta diferencias menores que requieren aclaración.',
                    'Presenta inconsistencias importantes.'],
                ['¿Cuál es el dictamen de la evidencia después de aplicar los criterios de evaluación nacional?',
                    'Aprobada: cumple con los criterios establecidos.',
                    'En revisión: requiere aclaraciones o validación adicional.',
                    'No aprobada: no cumple con los criterios establecidos.'],
            ];

            $rubric = Rubric::create([
                'title' => 'Rúbrica de evaluación nacional',
                'stage' => Rubric::NATIONAL,
                'is_active' => true,
            ]);
            foreach ($criteria as [$text, $complete, $partial, $insufficient]) {
                $question = $rubric->questions()->create(['text' => $text]);
                $question->options()->createMany([
                    ['text' => $complete, 'score' => 2],
                    ['text' => $partial, 'score' => 1],
                    ['text' => $insufficient, 'score' => 0],
                ]);
            }
        });
    }
}
