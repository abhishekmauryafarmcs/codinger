public class ArraySum {
    public static void main(String[] args) {
        // Sum the elements of an array
        int[] numbers = {1, 2, 3, 4, 5};
        int sum = 0;
        
        for (int num : numbers) {
            sum += num;
        }
        
        System.out.println("Sum of array elements: " + sum);
        System.out.println("Java Version: " + System.getProperty("java.version"));
    }
} 