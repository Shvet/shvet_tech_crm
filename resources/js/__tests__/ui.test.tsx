import { render, screen } from '@testing-library/react';
import { Field, StatusBanner, TextInput } from '../components/ui';

describe('form primitives', () => {
    it('associates label, input and error message accessibly', () => {
        render(
            <Field id="email" label="E-mail" error="Required">
                <TextInput id="email" error="Required" />
            </Field>,
        );
        const input = screen.getByLabelText('E-mail');
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input).toHaveAttribute('aria-describedby', 'email-error');
        expect(screen.getByRole('alert')).toHaveTextContent('Required');
    });

    it('renders nothing for an empty status', () => {
        const { container } = render(<StatusBanner message={null} />);
        expect(container).toBeEmptyDOMElement();
    });
});
