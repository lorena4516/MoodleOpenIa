<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_geniai\external;

use Exception;
use external_api;
use external_value;
use external_single_structure;
use external_function_parameters;
use local_geniai\markdown\parse_markdown;
use stdClass;
use local_geniai\external\create_course;

defined('MOODLE_INTERNAL') || die;
global $CFG;
require_once("{$CFG->dirroot}/lib/externallib.php");

/**
 * Chat file.
 *
 * @package     local_geniai
 * @copyright   2024 Eduardo Kraus https://eduardokraus.com/
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat extends external_api {
    /**
     * Parâmetros recebidos pelo webservice
     *
     * @return external_function_parameters
     */
    public static function api_parameters() {
        return new external_function_parameters([
            "message" => new external_value(PARAM_RAW, "The message value"),
            "courseid" => new external_value(PARAM_TEXT, "The Course ID"),
            "audio" => new external_value(PARAM_RAW, "The message value", VALUE_DEFAULT, null, NULL_ALLOWED),
            "lang" => new external_value(PARAM_RAW, "The language value", VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }

    /**
     * Identificador do retorno do webservice
     *
     * @return external_single_structure
     */
    public static function api_returns() {
        return new external_single_structure([
            "result" => new external_value(PARAM_TEXT, "Sucesso da operação", VALUE_REQUIRED),
            "format" => new external_value(PARAM_TEXT, "Formato da resposta", VALUE_REQUIRED),
            "content" => new external_value(PARAM_RAW, "The content result", VALUE_REQUIRED),
            "transcription" => new external_value(PARAM_RAW, "The content transcription", VALUE_OPTIONAL),
        ]);
    }

    /**
     * API para contabilizar o tempo gasto na plataforma pelos usuários
     *
     * @param string $message
     * @param int $courseid
     * @param null $audio
     * @param null $lang
     * @return array
     * @throws Exception
     */
    public static function api($message, $courseid, $audio = null, $lang = null) {
        global $DB, $CFG, $USER, $SITE;

        if (!$audio && self::is_course_creation_command($message)) {
            return self::handle_course_creation($message, $USER->id);
        }

        if (isset($USER->geniai[$courseid][0])) {
            $USER->geniai[$courseid] = [];
        }

        $returntranscription = false;
        if ($audio) {
            $transcription = api::transcriptions_base64($audio, $lang);
            $returntranscription = $transcription["text"];

            $audiolink = "<audio controls autoplay " .
                "src='{$CFG->wwwroot}/local/geniai/load-audio-temp.php?filename={$transcription["filename"]}'>" .
                "</audio><div class='transcription'>{$transcription["text"]}</div>";

            $message = [
                "role" => "user",
                "content" => $transcription["text"],
                "content_transcription" => $transcription["text"],
                "content_html" => $audiolink,
            ];
        } else {
            $message = [
                "role" => "user",
                "content" => strip_tags(trim($message)),
            ];
        }
        $USER->geniai[$courseid][] = $message;

        $course = $DB->get_record("course", ["id" => $courseid], "id, fullname");
        $textmodules = self::course_sections($course);
        $geniainame = get_config("local_geniai", "geniainame");
        $promptmessage = [
            "role" => "system",
            "content" => "Você é um chatbot chamado **{$geniainame}**.
Seu papel é ser um **superprofessor do Moodle \"{$SITE->fullname}\"**,
para o curso **[**{$course->fullname}**]({$CFG->wwwroot}/course/view.php?id={$course->id})**,
sempre prestativo e dedicado e você é especialista em apoiar e explicar tudo o que envolve o aprendizado.

## Módulos do curso:
{$textmodules}

### Suas respostas devem sempre seguir estas diretrizes:
* Seja **detalhado, claro e inspirador**, com um tom **amigável e motivador**.
* Dê atenção aos detalhes, oferecendo **exemplos práticos e explicações passo a passo** sempre que fizer sentido.
* Se a pergunta for ambígua, peça mais detalhes.
* Caso não souber, responda que não sabe, mas não crie algo que não lhe passei.
* Mantenha o **foco no Curso {$course->fullname}** e caso o usuário pedir fora do escopo, responda que não pode e nunca poderá.
* Use **somente formatação em MARKDOWN**.
* **SEMPRE** responda em **{$USER->lang}**, (nunca em outro idioma).

### Regras importantes:
* Nunca quebre o personagem de **professor do Moodle**.
* Jamais utilize linguagem neutra e mantenha sempre o tom acolhedor e professoral.
* Responda somente em MARKDOWN e no Idioma {$USER->lang}",
        ];

        $messages = array_slice($USER->geniai[$courseid], -9);
        array_unshift($messages, $promptmessage);

        $gpt = api::chat_completions(array_values($messages));
        if (isset($gpt["error"])) {
            $parsemarkdown = new parse_markdown();
            $content = $parsemarkdown->markdown_text($gpt["error"]["message"]);

            return [
                "result" => false,
                "format" => "text",
                "content" => $content,
                "transcription" => $returntranscription,
            ];
        }

        if (isset($gpt["choices"][0]["message"]["content"])) {
            $content = $gpt["choices"][0]["message"]["content"];

            $parsemarkdown = new parse_markdown();
            $content = $parsemarkdown->markdown_text($content);

            $USER->geniai[$courseid][] = [
                "role" => "system",
                "content" => $content,
            ];

            $format = "html";
            return [
                "result" => true,
                "format" => $format,
                "content" => $content,
                "transcription" => $returntranscription,
            ];
        }

        return [
            "result" => false,
            "format" => "text",
            "content" => "Error...",
        ];
    }

    /**
     * course_sections
     *
     * @param $course
     * @return string
     * @throws Exception
     */
    private static function course_sections($course) { // phpcs:disable moodle.Commenting.InlineComment.TypeHintingForeach
        global $USER;
        $textmodules = "";
        $modinfo = get_fast_modinfo($course->id, $USER->id);
        /** @var stdClass $sectioninfo */
        foreach ($modinfo->get_section_info_all() as $sectionnum => $sectioninfo) {
            if (empty($modinfo->sections[$sectionnum])) {
                continue;
            }

            $sectionname = get_section_name($course->id, $sectioninfo);
            $textmodules .= "* {$sectionname} \n";

            foreach ($modinfo->sections[$sectionnum] as $cmid) {
                $cm = $modinfo->cms[$cmid];
                if (!$cm->uservisible) {
                    continue;
                }

                $summary = null;
                if (isset($cm->summary)) {
                    $summary = format_string($cm->summary);
                    $summary = preg_replace('/<img[^>]*>/', '', $summary);
                    $summary = preg_replace('/\s+/', ' ', $summary);
                    $summary = trim(strip_tags($summary));
                }

                $url = $cm->url ? $cm->url->out(false) : "";
                $textmodules .= "** [{$cm->name}]({$url})\n";
                if (isset($summary[5])) {
                    $textmodules .= "*** summary: {$summary}\n";
                }
            }
        }

        return $textmodules;
    }

        /**
     * Verifica si el mensaje es un comando para crear curso
     *
     * @param string $message
     * @return bool
     */
    private static function is_course_creation_command($message) {
        $keywords = [
            'crear curso', 'crear un curso', 'nuevo curso', 'generar curso',
            'create course', 'make a course', 'new course'
        ];
        
        $message_lower = strtolower(trim($message));
        
        foreach ($keywords as $keyword) {
            if (strpos($message_lower, $keyword) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Maneja la creación de cursos via chat
     *
     * @param string $message
     * @param int $userid
     * @return array
     */
    private static function handle_course_creation($message, $userid) {
        global $USER, $CFG;
        
        // Verificar permisos
        $context = \context_system::instance();
        if (!has_capability('moodle/course:create', $context) && 
            !has_capability('moodle/site:config', $context)) {
            return [
                "result" => false,
                "format" => "html",
                "content" => "❌ **Permiso denegado**\n\nSolo los profesores y administradores pueden crear cursos."
            ];
        }
        
        // Extraer parámetros del mensaje
        $course_data = self::extract_course_params($message);
        if (!$course_data) {
            $help_text = "❌ **Formato incorrecto**\n\n" .
                        "Usa: **'Crear curso sobre [tema] con [X] semanas. Descripción: [descripción breve]'**\n\n" .
                        "**Ejemplo:**\n" .
                        "`Crear curso sobre Python con 4 semanas. Descripción: Curso básico de programación en Python para principiantes`";
            
            return [
                "result" => false,
                "format" => "html", 
                "content" => $help_text
            ];
        }
        
        try {
            // Llamar a la función de creación de curso
            $result = create_course::create_course(
                $course_data['topic'],
                $course_data['weeks'], 
                $course_data['description']
            );
            
            $success_message = "✅ **¡Curso creado exitosamente!** 🎉\n\n" .
                              "📚 **{$result['coursename']}**\n" .
                              "📅 **Duración:** {$course_data['weeks']} semanas\n" .
                              "👨‍🏫 **Creado por:** {$USER->firstname} {$USER->lastname}\n\n" .
                              "🔗 [Acceder al curso]({$result['courseurl']})";
            
            return [
                "result" => true,
                "format" => "html",
                "content" => $success_message
            ];
               
        } catch (\Exception $e) {
            return [
                "result" => false,
                "format" => "html",
                "content" => "❌ **Error al crear el curso**\n\n" . $e->getMessage()
            ];
        }
    }

    /**
     * Extrae los parámetros del curso del mensaje
     *
     * @param string $message
     * @return array|false
     */
    private static function extract_course_params($message) {
        $patterns = [
            '/crear\s+(?:un\s+)?curso\s+sobre\s+([^\.]+?)\s+con\s+(\d+)\s+semanas?\s*\.?\s*descripción:\s*(.+)/i',
            '/crear\s+(?:un\s+)?curso\s+de\s+([^\.]+?)\s+con\s+(\d+)\s+semanas?\s*\.?\s*descripción:\s*(.+)/i',
            '/crear\s+(?:un\s+)?curso\s+([^\.]+?)\s+(\d+)\s+semanas?\s*\.?\s*descripción:\s*(.+)/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return [
                    'topic' => trim($matches[1]),
                    'weeks' => (int)trim($matches[2]),
                    'description' => trim($matches[3])
                ];
            }
        }
        
        return false;
    }
}
