import { useEffect, useState } from 'react';

/**
 * useDebounce — หน่วงค่า value ตาม delay
 */
const useDebounce = (value, delay = 500) => {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);

    return debounced;
};

export default useDebounce;