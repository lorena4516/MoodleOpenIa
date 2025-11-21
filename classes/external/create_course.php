<?php

namespace local_geniai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

class create_course extends external_api {

    public static function create_course_parameters() {
        return new external_function_parameters([
            'topic' => new external_value(PARAM_TEXT, 'Tema del curso'),
            'weeks' => new external_value(PARAM_INT, 'Número de semanas'),
            'description' => new external_value(PARAM_RAW, 'Descripción breve del curso'),
        ]);
    }

    public static function create_course($topic, $weeks, $description) {
        global $CFG, $USER, $DB;

        // Validar parámetros
        $params = self::validate_parameters(self::create_course_parameters(), [
            'topic' => $topic,
            'weeks' => $weeks,
            'description' => $description
        ]);

        // Verificar permisos - solo profesores o administradores
        $context = \context_system::instance();
        require_capability('moodle/course:create', $context);

        // Validar número de semanas
        if ($weeks < 2 || $weeks > 52) {
            throw new \moodle_exception('Número de semanas inválido. Debe ser entre 2 y 52.');
        }

        // Prompt MEJORADO con la estructura exacta requerida
         
        $prompt = "Crea un curso sobre '{$topic}' con {$weeks} semanas. Descripción inicial: {$description}

DEVUELVE SOLO UN JSON con esta ESTRUCTURA EXACTA:

{
    \"course_name\": \"Nombre completo y atractivo del curso sobre {$topic}\",
    \"course_description\": \"Descripción completa en HTML con formato <p>, <ul>, <li> que detalle los objetivos, contenidos y beneficios del curso\",
    \"course_image\": \"https://source.unsplash.com/600x400/?{$topic},education,learning\",
    \"weeks\": [
        {
            \"week_number\": 1,
            \"title\": \"Introducción a {$topic}\",
            \"description\": \"Presentación completa del curso, objetivos de aprendizaje y conceptos fundamentales de {$topic}\",
            \"image\": \"https://source.unsplash.com/400x300/?introduction,beginning,{$topic}\"
        },";

        // Agregar semanas intermedias dinámicamente
        if ($weeks > 2) {
            $middle_weeks = $weeks - 2;
            $week_topics = [
                "Fundamentos esenciales",
                "Conceptos principales", 
                "Desarrollo práctico",
                "Aplicaciones avanzadas",
                "Proyectos integradores",
                "Casos de estudio"
            ];
            
            for ($i = 2; $i < $weeks; $i++) {
                $topic_index = min($i - 2, count($week_topics) - 1);
                $week_topic = $week_topics[$topic_index];
                
                $prompt .= "
        {
            \"week_number\": {$i},
            \"title\": \"{$topic} - {$week_topic}\",
            \"description\": \"Desarrollo detallado de contenidos específicos sobre {$topic}, incluyendo ejemplos prácticos y ejercicios\",
            \"image\": \"https://source.unsplash.com/400x300/?{$topic},learning,education\"
        },";
            }
        }

        // Semana final
        $prompt .= "
        {
            \"week_number\": {$weeks},
            \"title\": \"Cierre y conclusiones - {$topic}\",
            \"description\": \"Resumen integral del curso, conclusiones finales, evaluación de aprendizajes y recomendaciones para continuar el desarrollo en {$topic}\",
            \"image\": \"https://source.unsplash.com/400x300/?completion,graduation,success\"
        }
    ]
}

REGLAS ESTRICTAS:
- La SEMANA 1 DEBE llamarse 'Introducción a [tema]' 
- La SEMANA {$weeks} DEBE llamarse 'Cierre y conclusiones - [tema]'
- Usar imágenes reales de Unsplash
- Descripción en HTML válido con etiquetas
- Responde EXCLUSIVAMENTE con el JSON, sin texto adicional";

        // Configuración API
        $apikey = get_config('local_geniai', 'apikey');
        $model = get_config('local_geniai', 'model') ?: 'gpt-4';

        if (empty($apikey)) {
            throw new \moodle_exception('OpenAI API Key no configurada');
        }

        // Llamada a OpenAI
        $curl = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apikey
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system', 
                        'content' => 'Eres un asistente que responde EXCLUSIVAMENTE con JSON válido. No agregues texto, explicaciones, ni comentarios. Solo el JSON.'
                    ],
                    [
                        'role' => 'user', 
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 4000,
                'response_format' => ['type' => 'json_object']
            ]),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($httpcode !== 200) {
            error_log("OpenAI API Error - Code: {$httpcode}, Response: " . $response);
            throw new \moodle_exception('Error en API OpenAI. Código: ' . $httpcode);
        }

        $data = json_decode($response, true);
        
        if (!isset($data['choices'][0]['message']['content'])) {
            error_log("OpenAI Invalid Response: " . $response);
            throw new \moodle_exception('Respuesta inválida de OpenAI');
        }

        $content = $data['choices'][0]['message']['content'];
        
        // Limpiar y validar JSON
        $content = self::clean_json_response($content);
        $course_structure = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Parse Error: " . json_last_error_msg());
            error_log("Raw OpenAI Response: " . $content);
            throw new \moodle_exception('Error parseando JSON de OpenAI. La respuesta no es JSON válido.');
        }

        // Validar estructura REQUERIDA
        $required_fields = ['course_name', 'course_description', 'course_image', 'weeks'];
        foreach ($required_fields as $field) {
            if (!isset($course_structure[$field])) {
                throw new \moodle_exception("Campo requerido faltante: {$field}");
            }
        }

        // Validar semanas
        if (!is_array($course_structure['weeks']) || count($course_structure['weeks']) !== $weeks) {
            throw new \moodle_exception("Número incorrecto de semanas. Esperado: {$weeks}, Recibido: " . count($course_structure['weeks']));
        }

        // ✅ CORREGIDO: Validar semana 1 es introducción (validación más flexible)
        $first_week_title = strtolower($course_structure['weeks'][0]['title']);
        $introduction_keywords = ['introducción', 'introduccion', 'bienvenida', 'presentación', 'presentacion', 'inicio', 'comienzo'];
        $is_introduction = false;
        
        foreach ($introduction_keywords as $keyword) {
            if (strpos($first_week_title, $keyword) !== false) {
                $is_introduction = true;
                break;
            }
        }
        
        if (!$is_introduction) {
            // Forzar que la primera semana sea introducción
            $course_structure['weeks'][0]['title'] = "Introducción a {$topic}";
            $course_structure['weeks'][0]['description'] = "Presentación del curso, objetivos de aprendizaje y conceptos fundamentales de {$topic}";
        }

        // ✅ CORREGIDO: Validar última semana es cierre (validación más flexible)
        $last_week = end($course_structure['weeks']);
        $last_week_title = strtolower($last_week['title']);
        $closing_keywords = ['cierre', 'conclusión', 'conclusion', 'final', 'resumen', 'evaluación', 'evaluacion'];
        $is_closing = false;
        
        foreach ($closing_keywords as $keyword) {
            if (strpos($last_week_title, $keyword) !== false) {
                $is_closing = true;
                break;
            }
        }
        
        if (!$is_closing) {
            // Forzar que la última semana sea cierre
            $course_structure['weeks'][count($course_structure['weeks']) - 1]['title'] = "Cierre y conclusiones - {$topic}";
            $course_structure['weeks'][count($course_structure['weeks']) - 1]['description'] = "Resumen integral del curso, conclusiones finales y próximos pasos en {$topic}";
        }

        // Crear curso en Moodle
        $course = new \stdClass();
        $course->fullname = $course_structure['course_name'];
        $course->shortname = self::generate_shortname($course_structure['course_name']);
        $course->summary = $course_structure['course_description'];
        $course->summaryformat = FORMAT_HTML;
        $course->format = 'weeks';
        $course->numsections = $weeks;
        $course->startdate = time();
        $course->visible = 1;
        $course->category = 1;
        $course->timecreated = time();
        $course->timemodified = time();

        // Crear el curso
        $created_course = create_course($course);

        // Crear secciones/semanas
        self::create_course_sections($created_course->id, $course_structure['weeks']);

        // Enrollar al usuario creador como profesor
        self::enroll_user_as_teacher($created_course->id, $USER->id);

        return [
            'courseid' => $created_course->id,
            'coursename' => $created_course->fullname,
            'courseurl' => $CFG->wwwroot . '/course/view.php?id=' . $created_course->id
        ];
    }

    /**
     * Limpia la respuesta JSON
     */
    private static function clean_json_response($content) {
        // Remover ```json y ``` al inicio/final
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        
        // Remover posibles comentarios o texto extra
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        
        return trim($content);
    }

    private static function generate_shortname($fullname) {
        $shortname = preg_replace('/[^a-z0-9]/', '', strtolower($fullname));
        return substr($shortname, 0, 15) . '_' . time();
    }

    private static function create_course_sections($courseid, $weeks) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        // Asegurar que existan las secciones
        course_create_sections_if_missing($courseid, range(0, count($weeks)));

        foreach ($weeks as $week) {
            $section_num = $week['week_number'] - 1;
            
            if ($section_num >= 0) {
                $section = $DB->get_record('course_sections', [
                    'course' => $courseid, 
                    'section' => $section_num
                ]);
                
                if ($section) {
                    $section->name = $week['title'];
                    $section->summary = "<h3>{$week['title']}</h3>" .
                                      "<p>{$week['description']}</p>" .
                                      "<img src='{$week['image']}' alt='{$week['title']}' " .
                                      "style='max-width: 100%; height: auto; border-radius: 8px; margin: 10px 0;'>";
                    $section->summaryformat = FORMAT_HTML;
                    
                    $DB->update_record('course_sections', $section);
                }
            }
        }
    }

    private static function enroll_user_as_teacher($courseid, $userid) {
        global $DB;
        
        $context = \context_course::instance($courseid);
        $teacher_role = $DB->get_record('role', ['shortname' => 'editingteacher']);
        
        if ($teacher_role) {
            role_assign($teacher_role->id, $userid, $context->id);
        }
    }

    public static function create_course_returns() {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'ID del curso creado'),
            'coursename' => new external_value(PARAM_TEXT, 'Nombre del curso'),
            'courseurl' => new external_value(PARAM_URL, 'URL del curso')
        ]);
    }
}