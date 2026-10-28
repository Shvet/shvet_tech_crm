import { render, screen } from '@testing-library/react';
import Pagination from '../components/Pagination';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const links = [
    { url: null, label: '&laquo; Previous', active: false },
    { url: '/leads?page=1', label: '1', active: true },
    { url: '/leads?page=2', label: '2', active: false },
    { url: '/leads?page=2', label: 'Next &raquo;', active: false },
];

describe('Pagination', () => {
    it('renders nothing but a count for a single page, and nothing at all when empty', () => {
        const { container, rerender } = render(<Pagination page={{ links: [], last_page: 1, from: 1, to: 3, total: 3 }} />);
        expect(container).toHaveTextContent('3 results');
        expect(screen.queryByRole('navigation')).toBeNull();
        rerender(<Pagination page={{ links: [], last_page: 1, from: null, to: null, total: 0 }} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('marks the current page, disables unavailable links and decodes arrows', () => {
        render(<Pagination page={{ links, last_page: 2, from: 1, to: 20, total: 31 }} />);

        expect(screen.getByRole('navigation', { name: 'Pagination' })).toHaveTextContent('Showing 1–20 of 31');
        expect(screen.getByRole('link', { name: '1' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: '2' })).not.toHaveAttribute('aria-current');
        expect(screen.getByText('‹ Previous')).toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByRole('link', { name: '2' })).toHaveAttribute('href', '/leads?page=2');
        expect(screen.getByRole('link', { name: 'Next ›' })).toBeInTheDocument();
    });
});
