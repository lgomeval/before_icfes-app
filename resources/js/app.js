import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

document.addEventListener('livewire:initialized', () => {
    Livewire.on('show-correct', () => {
        Swal.fire({
            icon: 'success',
            title: '¡Correcto!',
            text: 'Muy bien, sigue así.',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            customClass: {
                popup: 'rounded-2xl',
            },
        }).then(() => {
            Livewire.dispatch('next-question');
        });
    });

    Livewire.on('show-incorrect', (data) => {
        const explanation = data[0]?.explanation ?? 'Inténtalo de nuevo.';

        Swal.fire({
            icon: 'error',
            title: '¡Incorrecto!',
            html: `<div class="text-left text-sm leading-relaxed">${explanation}</div>`,
            confirmButtonText: 'Siguiente pregunta',
            showCancelButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            customClass: {
                popup: 'rounded-2xl',
                confirmButton: 'bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-2 px-4 rounded-xl',
            },
        }).then(() => {
            Livewire.dispatch('next-question');
        });
    });
});
