🚀 Descripción del proyecto

Este proyecto consiste en la personalización y ampliación del plugin Open Source GeniAI para Moodle, disponible originalmente en:
https://moodle.org/plugins/local_geniai

El plugin fue modificado para la empresa EduLabs, incluyendo:

✔ Cambios visuales

Nuevo nombre del plugin.

Nuevo ícono con el logo de EduLabs.

Cambio completo de colores (look & feel) para ajustarse a la identidad visual de EduLabs.

Implementación del idioma español para toda la interfaz del plugin.

✔ Funcionalidades ampliadas — Función principal del proyecto

Se agregó una nueva funcionalidad avanzada que permite:

🧠 Creación automática de cursos usando OpenAI

Administradores y profesores pueden solicitarle al chatbot que genere un curso completo.
Los estudiantes NO pueden ejecutar esta función.

El usuario escribe en el chat algo como:

"Crea un curso sobre Ciberseguridad, duración 4 semanas, con una descripción corta."

El plugin usa la API de OpenAI para generar:

📌 Nombre del curso

📄 Descripción del curso en HTML

🖼 Imagen descriptiva del curso

🗂 Secciones semanales del curso, incluyendo:

nombre de cada semana

imagen ilustrativa por semana

semana 1 → Introducción

última semana → Cierre

Una vez retornada la información, el plugin:

✔ Crea automáticamente el curso en Moodle 4.5

Asigna formato semanal

Crea las secciones

Aplica descripciones

Inserta imágenes

Devuelve un enlace directo al curso recién creado

🧩 Requisitos

Moodle 4.5.x (probado en Moodle 4.5.7)

PHP 8.1 o superior

Servidor con cURL habilitado

Clave de API de OpenAI



## Instalación

Copiar la carpeta geniai dentro de:

/moodle/local/


Debe quedar así:

/moodle/local/geniai/


Ingresar a Moodle como administrador.

Moodle detectará el plugin → clic en Actualizar base de datos.

Ir a:

Administración del sitio → Plugins → Plugins locales → GeniAI (EduLabs)

Configurar la clave API de OpenAI.

Uso del Chatbot

Entrar a cualquier curso.

En el menú lateral → aparece el Chat GeniAI personalizado.

Escribir dudas o pedir explicaciones del curso.

Además, si eres profesor o administrador, puedes ejecutar:

🧠 Crear un curso mediante IA

Ejemplos:

Crea un curso sobre Introducción a la IA, con duración de 5 semanas y una breve descripción.
